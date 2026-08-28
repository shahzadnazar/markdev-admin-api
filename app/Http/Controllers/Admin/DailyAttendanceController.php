<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\DailyAttendance;
use App\Models\User;
use App\Support\AttendanceConfig;
use App\Support\AuditLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
    public const RANGES = ['today', 'yesterday', 'week', 'month', 'all', 'custom'];

    public function index(Request $request): View
    {
        $date = $this->date($request);
        $statusFilter = $this->statusFilter($request);
        $courseId = $request->filled('course') ? $request->integer('course') : null;

        $day = $date->toDateString();

        $students = $this->cohort($request, $courseId)
            ->with(['studentProfile:id,user_id,reg_no,cnic,attendance_slot_id', 'studentProfile.attendanceSlot:id,name,start_time,end_time', 'enrollments.course:id,title'])
            ->when($statusFilter === 'unmarked', fn($query) => $query
                ->whereDoesntHave('dailyAttendance', fn($inner) => $inner->onDate($day)))
            ->when($statusFilter !== null && $statusFilter !== 'unmarked', fn($query) => $query
                ->whereHas('dailyAttendance', fn($inner) => $inner->onDate($day)->where('status', $statusFilter)))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $studentIds = $students->pluck('id');

        $records = DailyAttendance::onDate($day)
            ->whereIn('user_id', $studentIds)
            ->with(['marker:id,name', 'marker.roles:id,name', 'updater:id,name'])
            ->get()
            ->keyBy('user_id');

        return view('admin.attendance.daily', [
    'students' => $students,
    'records' => $records,
    'history' => $this->historyFor($studentIds, $date),
    'date' => $date,
    'statusFilter' => $statusFilter,
    'courseId' => $courseId,
    'courses' => Course::orderBy('title')->get(['id', 'title']),
    'counts' => $this->dayCounts($request, $date, $courseId),
    'pinConfigured' => AttendanceConfig::hasEditPin(),
]);
    }

    /** Print the currently loaded register (same filters) as a PDF. */
    public function print(Request $request): Response
    {
        $date = $this->date($request);
        $statusFilter = $this->statusFilter($request);
        $courseId = $request->filled('course') ? $request->integer('course') : null;

        $day = $date->toDateString();

        $students = $this->cohort($request, $courseId)
            ->with(['studentProfile:id,user_id,reg_no', 'enrollments.course:id,title'])
            ->when($statusFilter === 'unmarked', fn($query) => $query
                ->whereDoesntHave('dailyAttendance', fn($inner) => $inner->onDate($day)))
            ->when($statusFilter !== null && $statusFilter !== 'unmarked', fn($query) => $query
                ->whereHas('dailyAttendance', fn($inner) => $inner->onDate($day)->where('status', $statusFilter)))
            ->orderBy('name')
            ->limit(1000)
            ->get();

        $records = DailyAttendance::onDate($day)
            ->whereIn('user_id', $students->pluck('id'))
            ->with(['marker:id,name', 'marker.roles:id,name'])
            ->get()
            ->keyBy('user_id');

        $pdf = Pdf::loadView('admin.attendance.pdf.register', [
            'students' => $students,
            'records' => $records,
            'date' => $date,
            'counts' => $this->dayCounts($request, $date, $courseId),
            'course' => $courseId ? Course::find($courseId) : null,
            'statusFilter' => $statusFilter,
            'search' => trim((string) $request->query('search')),
            'generatedBy' => $request->user()->name,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('daily-attendance-' . $date->toDateString() . '.pdf');
    }

    /** Per-student attendance history with range filters. */
    public function show(Request $request, User $student): View
    {
        abort_unless($student->hasRole('student'), 404);

        [$range, $statusFilter, $query] = $this->studentQuery($request, $student);

        $records = (clone $query)
            ->with(['marker:id,name', 'marker.roles:id,name', 'updater:id,name'])
            ->orderByDesc('date')
            ->paginate(31)
            ->withQueryString();

        return view('admin.attendance.student', [
            'student' => $student->load('studentProfile:id,user_id,reg_no', 'enrollments.course:id,title'),
            'records' => $records,
            'range' => $range,
            'statusFilter' => $statusFilter,
            'summary' => $this->studentSummary($request, $student),
        ]);
    }

    /** Print one student's filtered history as a PDF. */
    public function printStudent(Request $request, User $student): Response
    {
        abort_unless($student->hasRole('student'), 404);

        [$range, $statusFilter, $query] = $this->studentQuery($request, $student);

        $pdf = Pdf::loadView('admin.attendance.pdf.student', [
            'student' => $student->load('studentProfile:id,user_id,reg_no'),
            'records' => (clone $query)->with(['marker:id,name', 'marker.roles:id,name'])->orderByDesc('date')->limit(1000)->get(),
            'range' => $range,
            'statusFilter' => $statusFilter,
            'summary' => $this->studentSummary($request, $student),
            'generatedBy' => $request->user()->name,
        ])->setPaper('a4');

        return $pdf->stream('attendance-' . ($student->studentProfile?->reg_no ?? $student->id) . '.pdf');
    }

    /** First-time marking — no PIN, but only once per student per day. */
    public function mark(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'status' => ['required', Rule::in(DailyAttendance::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:500'],
            'arrived_at' => ['nullable', 'date_format:H:i'],
        ]);

        // Only one source may write to the register; while devices own it, a
        // manual mark would silently compete with the punch for the same day.
        if (\App\Support\AttendanceConfig::isBiometric()) {
            return back()->with('error', 'Attendance is set to biometric — device punches fill the register. An admin can switch to manual in Settings.');
        }

        $student = User::findOrFail($data['user_id']);
        abort_unless($student->hasRole('student') && $student->is_active, 422, 'Not an active student.');

        $exists = DailyAttendance::where('user_id', $student->id)
            ->onDate($data['date'])
            ->exists();

        if ($exists) {
            return back()->with('error', "{$student->name} is already marked for this date — use Update instead.");
        }

        DailyAttendance::create([
            'user_id' => $student->id,
            'date' => $data['date'],
            'status' => $data['status'],
            'remarks' => $data['remarks'] ?? null,
            'arrived_at' => $data['arrived_at'] ?? null,
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

        // Same rule as marking one student: while devices own the register,
        // filling it by hand would compete with the punches.
        if (\App\Support\AttendanceConfig::isBiometric()) {
            return back()->with('error', 'Attendance is set to biometric — device punches fill the register. An admin can switch to manual in Settings.');
        }

        // Only genuinely decided days count as marked. A pending row is a day
        // nobody has ruled on yet, so it should be filled, not skipped.
        $marked = DailyAttendance::onDate($data['date'])->counted()->pluck('user_id');

        $remaining = User::role('student')
            ->where('is_active', true)
            ->whereNotIn('id', $marked)
            ->get(['id', 'name']);

        foreach ($remaining as $student) {
            DailyAttendance::updateOrCreate(
                ['user_id' => $student->id, 'date' => $data['date']],
                [
                    'status' => 'present',
                    'source' => 'manual',
                    'marked_by' => $request->user()->id,
                    'marked_at' => now(),
                ],
            );
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
            'arrived_at' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'reason.required' => 'A reason is required for every attendance correction.',
        ]);

        if (! AttendanceConfig::hasEditPin()) {
            return back()->with('error', 'No attendance security PIN is configured yet — set one in System → Settings first.');
        }

        // Five PIN attempts per minute per user, then a cool-off.
        $throttleKey = 'attendance-pin:' . $request->user()->id;
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
            'arrived_at' => $data['arrived_at'] ?? $record->arrived_at,
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

    /* ------------------------------- Helpers ------------------------------- */

    /** Active students matching the shared search + course filters. */
    protected function cohort(Request $request, ?int $courseId)
    {
        return User::role('student')
            ->where('is_active', true)
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%' . trim($request->string('search')) . '%';
                $query->where(fn($inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhereHas('studentProfile', fn($profile) => $profile
                        ->where('reg_no', 'like', $term)
                        ->orWhere('cnic', 'like', $term)));
            })
            ->when($courseId, fn($query) => $query
                ->whereHas('enrollments', fn($inner) => $inner->where('course_id', $courseId)));
    }

    /**
     * Prior attendance for the students on this page, summarised in the database.
     *
     * The register shows four counts, a rate and the last five days per student.
     * It used to get those by loading every historical row for the page and
     * counting them in PHP, so the work grew with the whole table rather than
     * with the page — on a register with a few terms behind it that is enough
     * to run a request into its time limit. Now the database returns only the
     * numbers shown.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $studentIds
     * @return array<int, array<string, mixed>>
     */
    protected function historyFor($studentIds, \Illuminate\Support\Carbon $date): array
    {
        $blank = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'total' => 0, 'rate' => null, 'recent' => []];

        if ($studentIds->isEmpty()) {
            return [];
        }

        $day = $date->toDateString();
        $history = [];
        foreach ($studentIds as $id) {
            $history[$id] = $blank;
        }

        $counts = DailyAttendance::query()
            ->whereIn('user_id', $studentIds)
            ->where('date', '<', $day)
            ->counted()
            ->selectRaw('user_id, status, count(*) as total')
            ->groupBy('user_id', 'status')
            ->get();

        foreach ($counts as $row) {
            if (isset($history[$row->user_id])) {
                $history[$row->user_id][$row->status] = (int) $row->total;
            }
        }

        // Only five days are shown per student, so only five are fetched. The
        // window function ranks each student's days inside a single query,
        // rather than one query per row on the page.
        $ranked = DailyAttendance::query()
            ->select('user_id', 'date', 'status')
            ->selectRaw('row_number() over (partition by user_id order by date desc) as rn')
            ->whereIn('user_id', $studentIds)
            ->where('date', '<', $day)
            ->counted()
            ->toBase();

        $recent = \Illuminate\Support\Facades\DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('rn', '<=', 5)
            ->get()
            ->groupBy('user_id');

        foreach ($history as $id => $entry) {
            $total = $entry['present'] + $entry['late'] + $entry['absent'] + $entry['leave'];
            $history[$id]['total'] = $total;
            // Approved leave counts as attended — only genuine absences hurt.
            $history[$id]['rate'] = $total > 0
                ? round(($entry['present'] + $entry['late'] + $entry['leave']) / $total * 100, 1)
                : null;
            $history[$id]['recent'] = $recent->get($id, collect())
                ->sortBy('rn')
                ->map(fn ($row) => [
                    'date' => \Illuminate\Support\Carbon::parse($row->date)->format('M j, Y'),
                    'status' => $row->status,
                ])
                ->values()
                ->all();
        }

        return $history;
    }

    /** Day summary for the filtered cohort (search + course aware). */
    protected function dayCounts(Request $request, \Illuminate\Support\Carbon $date, ?int $courseId): array
    {
        $cohort = $this->cohort($request, $courseId);
        $cohortTotal = (clone $cohort)->count();

        // `counted` drops rows still pending: a day nobody has decided yet is
        // unmarked, not a status, and must not reach a count or a percentage.
        // The cohort goes in as a subquery: pulling every id out first meant
        // shipping thousands of them back as an IN list on every page load.
        $counts = DailyAttendance::onDate($date)
            ->whereIn('user_id', (clone $cohort)->select('users.id'))
            ->counted()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byStatus = [
            'present' => (int) ($counts['present'] ?? 0),
            'late' => (int) ($counts['late'] ?? 0),
            'absent' => (int) ($counts['absent'] ?? 0),
            'leave' => (int) ($counts['leave'] ?? 0),
        ];

        return $byStatus + [
            'unmarked' => max(0, $cohortTotal - array_sum($byStatus)),
            'total' => $cohortTotal,
            // Weighted, not a headcount: a late arrival still attended and
            // approved leave is not a mark against the student.
            'weighted_percent' => DailyAttendance::weightedPercent($byStatus),
        ];
    }

    /** @return array{0: string, 1: ?string, 2: \Illuminate\Database\Eloquent\Builder} */
    protected function studentQuery(Request $request, User $student): array
    {
        $range = in_array($request->query('range'), self::RANGES, true) ? $request->query('range') : 'month';
        $statusFilter = in_array($request->query('status'), DailyAttendance::STATUSES, true) ? $request->query('status') : null;

        $from = $range === 'custom' && $request->filled('from') ? $request->date('from') : null;
        $to = $range === 'custom' && $request->filled('to') ? $request->date('to') : null;
        if ($range === 'custom' && $from === null && $to === null) {
            $range = 'month'; // custom without dates falls back
        }

        // Plain comparisons rather than whereDate(): wrapping the column in a
        // date() call stops MySQL using the (user_id, date) index, and `date`
        // is already a date column so the two mean the same thing.
        $query = DailyAttendance::where('user_id', $student->id)
            ->when($range === 'today', fn($inner) => $inner->onDate(today()))
            ->when($range === 'yesterday', fn($inner) => $inner->onDate(today()->subDay()))
            ->when($range === 'week', fn($inner) => $inner->where('date', '>=', today()->startOfWeek()->toDateString()))
            ->when($range === 'month', fn($inner) => $inner->where('date', '>=', today()->startOfMonth()->toDateString()))
            ->when($from !== null, fn($inner) => $inner->where('date', '>=', $from->toDateString()))
            ->when($to !== null, fn($inner) => $inner->where('date', '<=', $to->toDateString()))
            ->when($statusFilter !== null, fn($inner) => $inner->where('status', $statusFilter));

        return [$range, $statusFilter, $query];
    }

    /** Range-filtered overview counts for one student (status filter excluded). */
    protected function studentSummary(Request $request, User $student): array
    {
        [,, $query] = $this->studentQuery($request->duplicate(array_merge($request->query(), ['status' => null])), $student);

        $counts = (clone $query)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $total = (int) $counts->sum();
        // Approved leave counts as attended — only genuine absences hurt the rate.
        $attended = (int) ($counts['present'] ?? 0) + (int) ($counts['late'] ?? 0) + (int) ($counts['leave'] ?? 0);

        return [
            'present' => (int) ($counts['present'] ?? 0),
            'late' => (int) ($counts['late'] ?? 0),
            'absent' => (int) ($counts['absent'] ?? 0),
            'leave' => (int) ($counts['leave'] ?? 0),
            'total' => $total,
            'rate' => $total > 0 ? round($attended / $total * 100, 1) : null,
        ];
    }

    protected function statusFilter(Request $request): ?string
    {
        return in_array($request->query('status'), [...DailyAttendance::STATUSES, 'unmarked'], true)
            ? $request->query('status')
            : null;
    }

    protected function date(Request $request): \Illuminate\Support\Carbon
    {
        $date = $request->filled('date') ? $request->date('date') : today();

        return $date->min(today())->startOfDay();
    }
}
