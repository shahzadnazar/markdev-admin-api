<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DailyAttendance;
use App\Models\LeaveApplication;
use App\Notifications\LeaveApplicationReviewed;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Reviews student leave applications. Approving one writes every day of
 * the range into the daily register as 'leave' (which counts as attended);
 * days where the student actually showed up (present/late) are left alone.
 */
class LeaveApplicationController extends Controller
{
    public const FILTERS = ['pending', 'approved', 'rejected', 'all'];

    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), self::FILTERS, true)
            ? $request->query('status')
            : 'pending';

        $leaves = LeaveApplication::with(['user:id,name,email', 'user.studentProfile:id,user_id,reg_no', 'reviewer:id,name'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.leaves.index', [
            'leaves' => $leaves,
            'status' => $status,
            'pendingCount' => LeaveApplication::pending()->count(),
        ]);
    }

    /** Approve: mark the range as 'leave' in the daily register + notify. */
    public function approve(Request $request, LeaveApplication $leave): RedirectResponse
    {
        if ($leave->status !== 'pending') {
            return back()->with('error', "This application was already {$leave->status} — it cannot be reviewed again.");
        }

        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $leave, $data) {
            $leave->update([
                'status' => 'approved',
                'review_note' => $data['review_note'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $existing = DailyAttendance::where('user_id', $leave->user_id)
                ->whereDate('date', '>=', $leave->from_date)
                ->whereDate('date', '<=', $leave->to_date)
                ->get()
                ->keyBy(fn ($record) => $record->date->toDateString());

            foreach ($leave->days() as $day) {
                $record = $existing->get($day->toDateString());

                // A student who actually showed up stays present/late.
                if ($record && in_array($record->status, ['present', 'late'], true)) {
                    continue;
                }

                if ($record) {
                    if ($record->status !== 'leave') {
                        $record->update([
                            'status' => 'leave',
                            'remarks' => 'Approved leave',
                            'last_updated_by' => $request->user()->id,
                            'last_update_reason' => 'Leave application approved',
                            'last_updated_at' => now(),
                        ]);
                    }

                    continue;
                }

                DailyAttendance::create([
                    'user_id' => $leave->user_id,
                    'date' => $day->toDateString(),
                    'status' => 'leave',
                    'remarks' => 'Approved leave',
                    'source' => 'manual',
                    'marked_by' => $request->user()->id,
                    'marked_at' => now(),
                ]);
            }
        });

        AuditLogger::log('leave_approved', 'leave_applications', $leave->id, null, [
            'student' => $leave->user?->name,
            'from' => $leave->from_date->toDateString(),
            'to' => $leave->to_date->toDateString(),
            'review_note' => $data['review_note'] ?? null,
        ]);

        $leave->user?->notify(new LeaveApplicationReviewed($leave));

        return back()->with('success', "Leave approved — {$leave->from_date->format('M j')} to {$leave->to_date->format('M j')} marked as leave for {$leave->user?->name}.");
    }

    /** Reject with an optional note — attendance is left untouched. */
    public function reject(Request $request, LeaveApplication $leave): RedirectResponse
    {
        if ($leave->status !== 'pending') {
            return back()->with('error', "This application was already {$leave->status} — it cannot be reviewed again.");
        }

        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $leave->update([
            'status' => 'rejected',
            'review_note' => $data['review_note'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        AuditLogger::log('leave_rejected', 'leave_applications', $leave->id, null, [
            'student' => $leave->user?->name,
            'from' => $leave->from_date->toDateString(),
            'to' => $leave->to_date->toDateString(),
            'review_note' => $data['review_note'] ?? null,
        ]);

        $leave->user?->notify(new LeaveApplicationReviewed($leave));

        return back()->with('success', "Leave request from {$leave->user?->name} rejected.");
    }
}
