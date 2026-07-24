<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DailyAttendance;
use App\Models\User;
use App\Support\AttendanceConfig;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The daily register: one row per active student per day. A record is
 * marked exactly once; corrections go through a PIN-gated update that
 * always records who, when and why.
 */
class DailyAttendanceController extends Controller
{
    public function index(Request $request): View
    {
        $date = $this->date($request);

        $statusFilter = in_array($request->query('status'), [...DailyAttendance::STATUSES, 'unmarked'], true)
            ? $request->query('status')
            : null;

        $students = User::role('student')
            ->where('is_active', true)
            ->with(['studentProfile:id,user_id,reg_no,cnic'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.trim($request->string('search')).'%';
                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term))
                    ->orWhereHas('studentProfile', fn ($profile) => $profile
                        ->where('reg_no', 'like', $term)
                        ->orWhere('cnic', 'like', $term));
            })
            ->when($statusFilter === 'unmarked', fn ($query) => $query
                ->whereDoesntHave('dailyAttendance', fn ($inner) => $inner->whereDate('date', $date)))
            ->when($statusFilter !== null && $statusFilter !== 'unmarked', fn ($query) => $query
                ->whereHas('dailyAttendance', fn ($inner) => $inner->whereDate('date', $date)->where('status', $statusFilter)))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $records = DailyAttendance::whereDate('date', $date)
            ->whereIn('user_id', $students->pluck('id'))
            ->with(['marker:id,name', 'updater:id,name'])
            ->get()
            ->keyBy('user_id');

        $activeTotal = User::role('student')->where('is_active', true)->count();
        $counts = DailyAttendance::whereDate('date', $date)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.attendance.daily', [
            'students' => $students,
            'records' => $records,
            'date' => $date,
            'statusFilter' => $statusFilter,
            'counts' => [
                'present' => (int) ($counts['present'] ?? 0),
                'late' => (int) ($counts['late'] ?? 0),
                'absent' => (int) ($counts['absent'] ?? 0),
                'leave' => (int) ($counts['leave'] ?? 0),
                'unmarked' => max(0, $activeTotal - (int) $counts->sum()),
                'total' => $activeTotal,
            ],
            'pinConfigured' => AttendanceConfig::hasEditPin(),
        ]);
    }

    /** First-time marking — no PIN, but only once per student per day. */
    public function mark(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'status' => ['required', Rule::in(DailyAttendance::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $student = User::findOrFail($data['user_id']);
        abort_unless($student->hasRole('student') && $student->is_active, 422, 'Not an active student.');

        $exists = DailyAttendance::where('user_id', $student->id)
            ->whereDate('date', $data['date'])
            ->exists();

        if ($exists) {
            return back()->with('error', "{$student->name} is already marked for this date — use Update instead.");
        }

        DailyAttendance::create([
            'user_id' => $student->id,
            'date' => $data['date'],
            'status' => $data['status'],
            'remarks' => $data['remarks'] ?? null,
            'source' => 'manual',
            'marked_by' => $request->user()->id,
            'marked_at' => now(),
        ]);

        AuditLogger::log('attendance_marked', 'daily_attendance', $student->id, null, [
            'student' => $student->name,
            'date' => $data['date'],
            'status' => $data['status'],
        ]);

        return back()->with('success', "{$student->name} marked {$data['status']}.");
    }

    /** Mark every still-unmarked active student present for the day. */
    public function bulkPresent(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $marked = DailyAttendance::whereDate('date', $data['date'])->pluck('user_id');

        $remaining = User::role('student')
            ->where('is_active', true)
            ->whereNotIn('id', $marked)
            ->get(['id', 'name']);

        foreach ($remaining as $student) {
            DailyAttendance::create([
                'user_id' => $student->id,
                'date' => $data['date'],
                'status' => 'present',
                'source' => 'manual',
                'marked_by' => $request->user()->id,
                'marked_at' => now(),
            ]);
        }

        AuditLogger::log('attendance_marked', 'daily_attendance', null, null, [
            'date' => $data['date'],
            'bulk' => 'remaining_present',
            'records' => $remaining->count(),
        ]);

        return back()->with('success', "{$remaining->count()} remaining student(s) marked present.");
    }

    /** PIN-gated correction — always records who, when and why. */
    public function update(Request $request, DailyAttendance $record): RedirectResponse
    {
        $data = $request->validate([
            'pin' => ['required', 'string', 'max:20'],
            'status' => ['required', Rule::in(DailyAttendance::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:500'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'reason.required' => 'A reason is required for every attendance correction.',
        ]);

        if (! AttendanceConfig::hasEditPin()) {
            return back()->with('error', 'No attendance security PIN is configured yet — set one in System → Settings first.');
        }

        // Five PIN attempts per minute per user, then a cool-off.
        $throttleKey = 'attendance-pin:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return back()->with('error', 'Too many wrong PIN attempts — wait a minute and try again.');
        }

        if (! AttendanceConfig::verifyEditPin($data['pin'])) {
            RateLimiter::hit($throttleKey, 60);

            return back()
                ->withErrors(['pin' => 'Incorrect security PIN.'])
                ->withInput($request->except('pin'))
                ->with('reopen_update', $record->id);
        }

        RateLimiter::clear($throttleKey);

        $old = ['status' => $record->status, 'remarks' => $record->remarks];

        $record->update([
            'status' => $data['status'],
            'remarks' => $data['remarks'] ?? null,
            'last_updated_by' => $request->user()->id,
            'last_update_reason' => $data['reason'],
            'last_updated_at' => now(),
        ]);

        AuditLogger::log('attendance_corrected', 'daily_attendance', $record->user_id, $old, [
            'student' => $record->user?->name,
            'date' => $record->date->toDateString(),
            'status' => $data['status'],
            'remarks' => $data['remarks'] ?? null,
            'reason' => $data['reason'],
        ]);

        return back()->with('success', 'Attendance updated — correction logged with your reason.');
    }

    protected function date(Request $request): \Illuminate\Support\Carbon
    {
        $date = $request->filled('date') ? $request->date('date') : today();

        return $date->min(today())->startOfDay();
    }
}
