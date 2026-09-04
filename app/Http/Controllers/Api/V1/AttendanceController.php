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

    /** The student's own daily-register history (date, status, remarks). */
    public function daily(Request $request): JsonResponse
    {
        $records = $this->dailyQuery($request)
            ->orderByDesc('date')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return response()->json([
            'data' => collect($records->items())->map(fn ($record) => [
                'id' => $record->id,
                'date' => $record->date->toDateString(),
                'status' => $record->status,
                'remarks' => $record->remarks,
                'arrived_at' => $record->arrived_at ? substr($record->arrived_at, 0, 5) : null,
                'source' => $record->source,
                'marked_at' => $record->marked_at?->toIso8601String(),
                'corrected' => $record->last_updated_at !== null,
            ])->all(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
                // The row numbers this page covers, so the pager can say which
                // slice of the register is on screen.
                'from' => $records->firstItem(),
                'to' => $records->lastItem(),
                // Across the student's whole register, not just this page, and
                // weighted: present 100, late 70, leave 50, absent 0.
                'weighted_percent' => \App\Models\DailyAttendance::weightedPercent(
                    \App\Models\DailyAttendance::where('user_id', $request->user()->id)
                        ->counted()
                        ->selectRaw('status, count(*) as total')
                        ->groupBy('status')
                        ->pluck('total', 'status')
                        ->toArray(),
                ),
            ],
        ]);
    }

    /**
     * Counts for the cards above the register.
     *
     * These read the daily register, the same rows the list below them shows.
     * They used to count AttendanceRecord — per-class attendance — which is a
     * different table with a different notion of a day: approved leave is
     * written to the register and never to it, so the Leave card sat at zero
     * on a page whose every visible day said Leave.
     */
    public function summary(Request $request): JsonResponse
    {
        $counts = $this->dailyQuery($request)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => [
                'total_sessions' => (int) $counts->sum(),
                'present_count' => (int) ($counts['present'] ?? 0),
                'absent_count' => (int) ($counts['absent'] ?? 0),
                'late_count' => (int) ($counts['late'] ?? 0),
                'leave_count' => (int) ($counts['leave'] ?? 0),
                // The register's own weighting — present 100, late 70, leave
                // 50, absent 0 — rather than a second definition of the rate
                // that would disagree with the one the list already reports.
                'attendance_rate' => \App\Models\DailyAttendance::weightedPercent($counts->toArray()) ?? 0,
            ],
        ]);
    }

    /**
     * The student's register, narrowed by the filters above the list.
     *
     * `date` is a date-cast column, so on SQLite it comes back as a full
     * datetime string and `date <= '2026-09-04'` would drop that whole day.
     * The upper bound is half-open for the same reason DailyAttendance::onDate
     * is, and it keeps the (user_id, date) index usable on MySQL either way.
     */
    protected function dailyQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = \App\Models\DailyAttendance::where('user_id', $request->user()->id)->counted();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->query('from')) {
            $query->where('date', '>=', \Illuminate\Support\Carbon::parse($from)->toDateString());
        }

        if ($to = $request->query('to')) {
            $query->where('date', '<', \Illuminate\Support\Carbon::parse($to)->addDay()->toDateString());
        }

        return $query;
    }
}
