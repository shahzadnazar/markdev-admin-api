<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TeamLeaveApplication;
use App\Support\AuditLogger;
use App\Support\TeamLeaveAllowance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Team leave: anybody applies, ONLY AN ADMIN REVIEWS.
 *
 * ## Why a team-lead cannot approve or decline
 *
 * Two reasons, and either would be enough on its own.
 *
 * Pay and attendance are not a lead's job here. A lead runs the work; time off
 * is a staff matter, and the team portal deliberately keeps a lead away from
 * what a colleague earns, owes or is charged.
 *
 * And a lead reviewing their own team's leave is a conflict they should not be
 * asked to hold. The lead is SCORED on that team's delivery — phase 3's
 * delivery score is computed from stints on their team's tasks — so approving
 * leave costs them something and declining it gains them something. Nobody
 * should have to make that call about the person sitting next to them. That
 * includes their own application, which is the same conflict with the paperwork
 * removed.
 *
 * The review flow itself is the academy's, over the team's own tables: per-day
 * partial approval, a written reason required the moment anything is declined,
 * and that reason shown to the member. The mechanics are
 * DecidesLeavePerDay, extracted rather than copied.
 */
class TeamLeaveController extends Controller
{
    /** A member's own applications, and the form to add one. */
    public function mine(Request $request): View
    {
        $user = $request->user();

        return view('admin.team-leave.mine', [
            'applications' => TeamLeaveApplication::query()
                ->where('user_id', $user->id)
                ->with(['decisions', 'reviewer:id,name'])
                ->orderByDesc('id')
                ->get(),
            'balance' => TeamLeaveAllowance::balance($user->id, Carbon::today()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $user = $request->user();

        $leave = new TeamLeaveApplication([
            'user_id' => $user->id,
            'from_date' => TeamLeaveApplication::dayKey($data['from_date']),
            'to_date' => TeamLeaveApplication::dayKey($data['to_date']),
            'reason' => $data['reason'],
        ]);

        // Checked against the month the range STARTS in, which is the month the
        // reservation lands in. A range crossing a month boundary is rare
        // enough that splitting it is a clearer instruction than a rule nobody
        // can predict from the form.
        $balance = TeamLeaveAllowance::balance($user->id, Carbon::parse($data['from_date']));
        $wanted = count($leave->days());

        if ($wanted === 0) {
            throw ValidationException::withMessages([
                'from_date' => 'Those are all non-working days — there is nothing to take leave from.',
            ]);
        }

        if ($wanted > $balance['remaining']) {
            throw ValidationException::withMessages([
                'from_date' => sprintf(
                    'That is %d working day(s), and only %d of your %d for %s remain.',
                    $wanted,
                    $balance['remaining'],
                    $balance['allowance'],
                    $balance['month_label'],
                ),
            ]);
        }

        DB::transaction(function () use ($leave) {
            $leave->save();
            // Opened as pending, which is what reserves the days: several
            // requests filed at once must not all fit inside one allowance.
            $leave->openDecisions();
        });

        return back()->with('success', 'Leave applied for. An admin will review it.');
    }

    /* -------------------------------- Review -------------------------------- */

    /** The review queue. Admin only — see the class docblock. */
    public function index(Request $request): View
    {
        return view('admin.team-leave.index', [
            'applications' => TeamLeaveApplication::query()
                ->with(['user:id,name', 'decisions', 'reviewer:id,name'])
                ->orderByRaw("case when status = 'pending' then 0 else 1 end")
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function review(Request $request, TeamLeaveApplication $leave): RedirectResponse
    {
        if ($leave->status !== 'pending') {
            return back()->with('error', "This application was already {$leave->status} — it cannot be reviewed again.");
        }

        $rangeDates = collect($leave->days())->map->toDateString();

        // "Decline all" is its own submit rather than an empty tick list, so
        // the outcome does not depend on the browser clearing checkboxes.
        $declineAll = $request->boolean('decline_all');

        $validator = Validator::make($request->all(), [
            'days' => ['sometimes', 'array'],
            'days.*' => ['date', Rule::in($rangeDates->all())],
            // Required the moment anything is turned down. Somebody told "no"
            // is owed the reason, and a full approval needs no explaining.
            'review_note' => [
                Rule::requiredIf(fn () => $declineAll
                    || count(array_unique($request->input('days', []))) < $rangeDates->count()),
                'nullable', 'string', 'max:1000',
            ],
        ], [
            'review_note.required' => 'A note is required when any day is declined — the member is told why.',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput()->with('review_leave', $leave->id);
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

            return $status;
        });

        $approved = count(array_unique($approvedDates));
        $declined = $rangeDates->count() - $approved;

        AuditLogger::log('leave_reviewed', 'team_leave_applications', $leave->id, null, [
            'member' => $leave->user?->name,
            'from' => $leave->from_date->toDateString(),
            'to' => $leave->to_date->toDateString(),
            'outcome' => $status,
            'approved_days' => $approved,
            'declined_days' => $declined,
            'review_note' => $note,
        ]);

        return back()->with('success', match ($status) {
            'approved' => "Leave approved for {$leave->user?->name} — {$approved} day(s).",
            'rejected' => "Leave declined for {$leave->user?->name}.",
            default => "Leave partly approved for {$leave->user?->name} — {$approved} approved, {$declined} declined.",
        });
    }
}
