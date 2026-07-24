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
        $records = \App\Models\DailyAttendance::where('user_id', $request->user()->id)
            ->orderByDesc('date')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return response()->json([
            'data' => collect($records->items())->map(fn ($record) => [
                'id' => $record->id,
                'date' => $record->date->toDateString(),
                'status' => $record->status,
                'remarks' => $record->remarks,
                'source' => $record->source,
                'marked_at' => $record->marked_at?->toIso8601String(),
                'corrected' => $record->last_updated_at !== null,
            ])->all(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $counts = AttendanceRecord::where('user_id', $request->user()->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = (int) $counts->sum();
        $present = (int) ($counts['present'] ?? 0);

        return response()->json([
            'data' => [
                'total_sessions' => $total,
                'present_count' => $present,
                'absent_count' => (int) ($counts['absent'] ?? 0),
                'late_count' => (int) ($counts['late'] ?? 0),
                'excused_count' => (int) ($counts['excused'] ?? 0),
                'attendance_rate' => $total > 0 ? round($present / $total * 100, 1) : 0,
            ],
        ]);
    }
}
