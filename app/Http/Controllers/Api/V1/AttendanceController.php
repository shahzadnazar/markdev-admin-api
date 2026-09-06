<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AttendanceRecordResource;
use App\Models\AttendanceRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AttendanceController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AttendanceRecord::where('user_id', $request->user()->id)->with('course');

        if ($courseId = $request->query('course_id')) {
            $query->where('course_id', $courseId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('date', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('date', '<=', $to);
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where('session_title', 'like', "%{$search}%");
        }

        $records = $query->orderByDesc('date')->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return AttendanceRecordResource::collection($records);
    }

    /**
     * Every day the academy has a record of, newest first.
     *
     * A student's attendance lives in two tables that only partly overlap:
     * `attendance_records` is per class session and carries the session title,
     * `daily_attendance_records` is the day register and is the only place an
     * approved leave is written. Listing either one alone drops days the
     * student can see they attended, so the list is the union of their dates.
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
     * The same union the list shows, so the two never disagree. These used to
     * count `attendance_records` alone, which an approved leave never touches:
     * the Leave card sat at zero on a page whose every listed day said Leave.
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
     * One row per date, merged from both tables.
     *
     * Bounded by how long a student attends -- a few hundred rows -- so the
     * union and its paging are done here rather than as a cross-engine UNION
     * query that would have to reconcile two different column sets.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function mergedDays(Request $request, bool $applyStatus = true): \Illuminate\Support\Collection
    {
        $userId = $request->user()->id;

        // `date` is a date-cast column, so SQLite hands it back as a full
        // datetime and `date <= '2026-09-04'` would drop that whole day. The
        // upper bound is half-open for the same reason DailyAttendance::onDate
        // is, and it keeps the index usable on MySQL either way.
        $bound = function ($query) use ($request) {
            if ($from = $request->query('from')) {
                $query->where('date', '>=', \Illuminate\Support\Carbon::parse($from)->toDateString());
            }

            if ($to = $request->query('to')) {
                $query->where('date', '<', \Illuminate\Support\Carbon::parse($to)->addDay()->toDateString());
            }

            return $query;
        };

        $rows = [];

        foreach ($bound(AttendanceRecord::where('user_id', $userId)->with('course:id,title'))->orderBy('id')->get() as $session) {
            $date = \Illuminate\Support\Carbon::parse($session->date)->toDateString();

            // A day with two sessions keeps the first: the column names the
            // day's class, it is not a session list.
            $rows[$date] ??= [
                'id' => $date,
                'date' => $date,
                // This table says "excused" where the register says "leave".
                // One vocabulary reaches the portal.
                'status' => $session->status === 'excused' ? 'leave' : $session->status,
                'session_title' => $session->session_title,
                'course' => $session->course
                    ? ['id' => $session->course->id, 'title' => $session->course->title]
                    : null,
                'arrived_at' => null,
                'remarks' => $session->notes,
                'source' => null,
                'marked_at' => null,
                'corrected' => false,
            ];
        }

        // `decided`, not `counted`: a holiday belongs on the list — a gap
        // where Eid was reads as missing data — but never in a count. The
        // callers above are what keep those two apart.
        foreach ($bound(\App\Models\DailyAttendance::where('user_id', $userId)->decided())->get() as $day) {
            $date = $day->date->toDateString();
            $session = $rows[$date] ?? null;

            $rows[$date] = [
                'id' => $date,
                'date' => $date,
                // The register is the academy's own word on the day, so it
                // wins where both have one; the session only lends its title.
                'status' => $day->status,
                'session_title' => $session['session_title'] ?? null,
                'course' => $session['course'] ?? null,
                'arrived_at' => $day->arrived_at ? substr($day->arrived_at, 0, 5) : null,
                'remarks' => $day->remarks,
                'source' => $day->source,
                'marked_at' => $day->marked_at?->toIso8601String(),
                'corrected' => $day->last_updated_at !== null,
            ];
        }

        // After the merge, not before: the status a day ends up with can come
        // from either table.
        $status = $applyStatus ? $request->query('status') : null;

        return collect($rows)
            ->when($status, fn ($days) => $days->where('status', $status))
            ->sortByDesc('date')
            ->values();
    }
}
