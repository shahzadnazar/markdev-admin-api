<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AttendanceDayResource;
use App\Models\DailyAttendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AttendanceController extends ApiController
{
    use Concerns\FiltersByValues;

    /** Everything a day can be, and everything the status filter will accept. */
    protected function listableStatuses(): array
    {
        return [...DailyAttendance::STATUSES, DailyAttendance::HOLIDAY];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = DailyAttendance::where('user_id', $request->user()->id)->decided()->with('course');

        if ($courseId = $request->query('course_id')) {
            $query->where('course_id', $courseId);
        }

        // Several statuses at once. An empty selection is no filter, and an
        // older app build still sends ?status=absent, which normalises to one.
        if ($statuses = $this->filterValues($request, 'status', $this->listableStatuses())) {
            $query->whereIn('status', $statuses);
        }

        // Half-open on the upper bound rather than a `<=` against a date-cast
        // column, which drops that whole day on any store keeping the time part.
        if ($from = $request->query('from')) {
            $query->where('date', '>=', \Illuminate\Support\Carbon::parse($from)->toDateString());
        }

        if ($to = $request->query('to')) {
            $query->where('date', '<', \Illuminate\Support\Carbon::parse($to)->addDay()->toDateString());
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where('session_title', 'like', "%{$search}%");
        }

        $records = $query->orderByDesc('date')->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return AttendanceDayResource::collection($records);
    }

    /**
     * Every day the academy has a record of, newest first.
     *
     * This used to be the union of two tables that only partly overlapped.
     * They are one table now, so the list is simply the register's own days —
     * but the shape it returns is unchanged, session title and course
     * included, because the portal reads it and the merge is not the
     * student's business.
     */
    public function daily(Request $request): JsonResponse
    {
        $days = $this->mergedDays($request);

        $perPage = $this->perPage($request);
        $page = max(1, (int) $request->query('page', 1));
        $items = $days->forPage($page, $perPage)->values();
        $total = $days->count();

        return response()->json([
            'data' => $items->all(),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
                // The row numbers this page covers, so the pager can say which
                // slice is on screen.
                'from' => $total > 0 ? ($page - 1) * $perPage + 1 : null,
                'to' => $total > 0 ? ($page - 1) * $perPage + $items->count() : null,
                // weightedPercent only knows the four real statuses, so the
                // holiday rows in this list contribute nothing to it.
                'weighted_percent' => \App\Models\DailyAttendance::weightedPercent(
                    $this->mergedDays($request, false)->countBy('status')->all(),
                ),
            ],
        ]);
    }

    /**
     * Counts for the cards above the list.
     *
     * The same days the list shows, so the two never disagree. These once
     * counted the class-attendance table alone, which an approved leave never
     * touched: the Leave card sat at zero on a page whose every listed day
     * said Leave. One table cannot drift from itself.
     */
    public function summary(Request $request): JsonResponse
    {
        // Not narrowed by the status filter: cards that only ever counted the
        // status you filtered to would say nothing.
        $counts = $this->mergedDays($request, false)->countBy('status');

        // Holidays are listed but never counted. Summing every key would put
        // days the academy was shut into the denominator of the rate, which
        // would quietly lower it for every student in a month with an Eid.
        $counted = $counts->only(\App\Models\DailyAttendance::STATUSES);

        return response()->json([
            'data' => [
                'total_sessions' => (int) $counted->sum(),
                'present_count' => (int) ($counts['present'] ?? 0),
                'absent_count' => (int) ($counts['absent'] ?? 0),
                'late_count' => (int) ($counts['late'] ?? 0),
                'leave_count' => (int) ($counts['leave'] ?? 0),
                // Its own card rather than added to Leave. A day the academy
                // excused is not a day the student spent from their leave
                // allowance, and the class sheet's old habit of reporting one
                // as the other is exactly what this consolidation removes.
                'excused_count' => (int) ($counts['excused'] ?? 0),
                // The register's own weighting -- present 100, late 70, leave
                // 50, absent 0 -- rather than a second definition of the rate
                // that would disagree with the one the list reports.
                'attendance_rate' => \App\Models\DailyAttendance::weightedPercent($counted->all()) ?? 0,
                // Days off in this window, so the portal can label them
                // without a calendar of its own.
                'holiday_count' => (int) ($counts[\App\Models\DailyAttendance::HOLIDAY] ?? 0),
                // What this month's absences are costing. Every number comes
                // from the admin settings; the portal computes none of it.
                'absence_balance' => \App\Support\AbsenceFine::balance(
                    $request->user()->id,
                    \Illuminate\Support\Carbon::now(),
                ),
            ],
        ]);
    }

    /**
     * The academy's working week and its holidays, for a date window.
     *
     * The leave picker greys out days that cost nothing and the attendance
     * list labels the holidays, so both need the same two facts. Serving them
     * rather than letting the portal assume Mon–Fri is the point: the working
     * week is an admin setting and holidays are rows, and either can change
     * between one page load and the next.
     *
     * `working_days` is this student's own week — their slot's days if they
     * are on one, the academy's otherwise — because that is what decides
     * whether a day of theirs is chargeable.
     */
    public function calendar(Request $request): JsonResponse
    {
        $student = $request->user()->loadMissing('studentProfile.attendanceSlot');
        $slot = $student->studentProfile?->attendanceSlot;

        // Defaults cover the window the leave form can reach: 60 days ahead,
        // and back to the start of last month so the attendance list is
        // covered by the same call.
        $from = \Illuminate\Support\Carbon::parse($request->query('from') ?: now()->subMonth()->startOfMonth())->startOfDay();
        $to = \Illuminate\Support\Carbon::parse($request->query('to') ?: now()->addDays(60))->startOfDay();

        // A window a client can ask for, not one it can weaponise.
        if ($to->lessThan($from)) {
            $to = $from->copy();
        }
        if ($from->diffInDays($to) > 400) {
            $to = $from->copy()->addDays(400);
        }

        $days = $slot?->dayNumbers() ?? \App\Support\AcademyCalendar::workingDays();

        return response()->json([
            'data' => [
                'working_days' => $days,
                'working_days_label' => \App\Models\AttendanceSlot::labelForDays($days),
                // Named so the portal can say why a day is greyed out: their
                // slot's timetable is not the same fact as the academy's week.
                'source' => $slot !== null ? 'slot' : 'academy',
                'slot_name' => $slot?->name,
                'holidays' => \App\Support\AcademyCalendar::holidayMap($from, $to)
                    ->map(fn (string $name, string $date) => ['date' => $date, 'name' => $name])
                    ->values()
                    ->all(),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }

    /**
     * One row per date, newest first.
     *
     * Was a merge of two tables; the class-attendance sheet was folded into
     * the register, so this reads one. The shape it returns is deliberately
     * unchanged — the portal renders these rows and the consolidation is an
     * admin-side concern.
     *
     * Still built in PHP rather than paged in SQL: a student's history is a
     * few hundred rows, and the status filter has to be applied to the
     * resolved status, which is what the `$applyStatus` flag is for.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function mergedDays(Request $request, bool $applyStatus = true): \Illuminate\Support\Collection
    {
        // `date` is a date-cast column, so SQLite hands it back as a full
        // datetime and `date <= '2026-09-04'` would drop that whole day. The
        // upper bound is half-open for the same reason DailyAttendance::onDate
        // is, and it keeps the index usable on MySQL either way.
        $query = DailyAttendance::where('user_id', $request->user()->id)
            // `decided`, not `counted`: a holiday belongs on the list — a gap
            // where Eid was reads as missing data — but never in a count. The
            // callers above are what keep those two apart.
            ->decided()
            ->with('course:id,title');

        if ($from = $request->query('from')) {
            $query->where('date', '>=', \Illuminate\Support\Carbon::parse($from)->toDateString());
        }

        if ($to = $request->query('to')) {
            $query->where('date', '<', \Illuminate\Support\Carbon::parse($to)->addDay()->toDateString());
        }

        $statuses = $applyStatus ? $this->filterValues($request, 'status', $this->listableStatuses()) : [];

        return $query->get()
            ->map(fn (DailyAttendance $day) => [
                // The date is the id: one row per day is what the unique
                // (user_id, date) guarantees, and the portal keys on it.
                'id' => $day->date->toDateString(),
                'date' => $day->date->toDateString(),
                'status' => $day->status,
                'session_title' => $day->session_title,
                'course' => $day->course
                    ? ['id' => $day->course->id, 'title' => $day->course->title]
                    : null,
                'arrived_at' => $day->arrived_at ? substr($day->arrived_at, 0, 5) : null,
                'remarks' => $day->remarks,
                'source' => $day->source,
                'marked_at' => $day->marked_at?->toIso8601String(),
                'corrected' => $day->last_updated_at !== null,
            ])
            ->when($statuses, fn ($days) => $days->whereIn('status', $statuses))
            ->sortByDesc('date')
            ->values();
    }
}
