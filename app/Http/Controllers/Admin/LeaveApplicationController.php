<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveApplication;
use App\Notifications\LeaveApplicationReviewed;
use App\Models\DailyAttendance;
use App\Support\AbsenceFine;
use App\Support\AuditLogger;
use App\Support\LeaveAllowance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Reviews student leave applications, one day at a time.
 *
 * A reviewer approves the days they accept and declines the rest, and the
 * verdicts are stored per day. Nothing touches the daily register here: the
 * end-of-day close reads the approved days when it settles each day. That
 * ordering is what stops a future approval writing a day that has not
 * happened, and stops a leave overwriting a student who turned up anyway.
 */
class LeaveApplicationController extends Controller
{
    public const FILTERS = ['pending', 'approved', 'partially_approved', 'rejected', 'all'];

    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), self::FILTERS, true)
            ? $request->query('status')
            : 'pending';

        $leaves = LeaveApplication::with(['user:id,name,email', 'user.studentProfile:id,user_id,reg_no', 'reviewer:id,name', 'decisions'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // The student's standing in the month each request falls in. An
        // over-limit request cannot reach this screen, so this is context for
        // the reviewer rather than a warning: it says how much of the month
        // this one request is spending.
        $balances = $leaves->getCollection()
            ->mapWithKeys(fn (LeaveApplication $leave) => [
                $leave->id => LeaveAllowance::balance($leave->user_id, $leave->from_date),
            ]);

        return view('admin.leaves.index', [
            'leaves' => $leaves,
            'balances' => $balances,
            'status' => $status,
            'pendingCount' => LeaveApplication::pending()->count(),
        ]);
    }

    /**
     * Record the verdict on each day of the range.
     *
     * `days[]` carries the dates the reviewer ticked; everything else in the
     * range is declined. An empty list is a full rejection, which is what the
     * "Decline all" action posts.
     */
    public function review(Request $request, LeaveApplication $leave): RedirectResponse
    {
        if ($leave->status !== 'pending') {
            return back()->with('error', "This application was already {$leave->status} — it cannot be reviewed again.");
        }

        $rangeDates = collect($leave->days())->map->toDateString();

        // "Decline all" is its own submit rather than an empty tick list, so
        // the outcome does not depend on the browser clearing checkboxes
        // before the form posts.
        $declineAll = $request->boolean('decline_all');

        $validator = Validator::make($request->all(), [
            'days' => ['sometimes', 'array'],
            'days.*' => ['date', Rule::in($rangeDates->all())],
            // Required the moment anything is turned down: a student told "no"
            // is owed the reason, and a full approval needs no explaining.
            'review_note' => [
                Rule::requiredIf(fn () => $declineAll
                    || count(array_unique($request->input('days', []))) < $rangeDates->count()),
                'nullable',
                'string',
                'max:1000',
            ],
        ], [
            'review_note.required' => 'A note is required when any day is declined — the student is told why.',
        ]);

        if ($validator->fails()) {
            // Validated by hand only so the failure can name its application:
            // the review form lives in a modal, and a bare redirect back shows
            // the list with the error hidden behind a closed dialog.
            return back()
                ->withErrors($validator)
                ->withInput()
                ->with('review_leave', $leave->id);
        }

        $data = $validator->validated();

        $approvedDates = $declineAll ? [] : ($data['days'] ?? []);
        $note = $data['review_note'] ?? null;

        $status = DB::transaction(function () use ($request, $leave, $approvedDates, $note) {
            $status = $leave->recordDecisions($approvedDates);

            $leave->update([
                'status' => $status,
                'review_note' => $note,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $this->releaseClosedAbsences($request, $leave, $approvedDates);

            return $status;
        });

        $approved = count(array_unique($approvedDates));
        $declined = $rangeDates->count() - $approved;

        AuditLogger::log('leave_reviewed', 'leave_applications', $leave->id, null, [
            'student' => $leave->user?->name,
            'from' => $leave->from_date->toDateString(),
            'to' => $leave->to_date->toDateString(),
            'outcome' => $status,
            'approved_days' => $approved,
            'declined_days' => $declined,
            'review_note' => $note,
        ]);

        $leave->user?->notify(new LeaveApplicationReviewed($leave->fresh()));

        return back()->with('success', match ($status) {
            'approved' => "Leave approved for {$leave->user?->name} — {$approved} day(s).",
            'rejected' => "Leave declined for {$leave->user?->name}.",
            default => "Leave partly approved for {$leave->user?->name} — {$approved} day(s) approved, {$declined} declined.",
        });
    }

    /**
     * Turn an already-closed absence into leave when its day is approved.
     *
     * Reviewing does not write the register — the day close does, reading the
     * approved days. But a day that closed before anyone got to the
     * application is already marked absent, and the close will not revisit a
     * day it has settled. Without this, applying in time and being approved
     * late would still cost the student the absence, and possibly a fine.
     *
     * Only an absence is touched. A student recorded present or late actually
     * turned up, and that stands whatever their application said.
     *
     * @param  array<int, string>  $approvedDates
     */
    protected function releaseClosedAbsences(Request $request, LeaveApplication $leave, array $approvedDates): void
    {
        if ($approvedDates === []) {
            return;
        }

        $approved = collect($approvedDates)->map(fn ($date) => Carbon::parse($date)->toDateString());

        // Matched on Y-m-d in PHP: `date` is a date-cast column, so an
        // equality against "2026-09-02" finds nothing on SQLite.
        $rows = DailyAttendance::where('user_id', $leave->user_id)
            ->where('date', '>=', $leave->from_date->toDateString())
            ->where('date', '<', $leave->to_date->copy()->addDay()->toDateString())
            ->where('status', 'absent')
            ->get()
            ->filter(fn (DailyAttendance $row) => $approved->contains($row->date->toDateString()));

        foreach ($rows as $row) {
            $old = ['status' => $row->status, 'remarks' => $row->remarks];

            $row->update([
                'status' => 'leave',
                'remarks' => 'Approved leave',
                'last_updated_by' => $request->user()->id,
                'last_update_reason' => 'Leave application approved after the day closed',
                'last_updated_at' => now(),
            ]);

            AuditLogger::log('attendance_corrected', 'daily_attendance', $row->user_id, $old, [
                'student' => $leave->user?->name,
                'date' => $row->date->toDateString(),
                'status' => 'leave',
                'reason' => 'Leave application approved after the day closed',
            ]);

            // It has stopped being a chargeable absence.
            AbsenceFine::reconcile($row->user_id, $row->date->copy()->startOfMonth());
        }
    }
}
