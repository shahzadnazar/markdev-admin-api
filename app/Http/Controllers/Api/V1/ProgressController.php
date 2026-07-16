<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\CourseProgressResource;
use App\Models\LearningActivity;
use App\Services\LearningStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgressController extends ApiController
{
    public function __invoke(Request $request, LearningStatsService $stats): JsonResponse
    {
        $user = $request->user();

        $enrollments = $user->enrollments()
            ->with('course')
            ->orderByRaw('last_activity_at is null')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->get();
        $stats->hydrateCourseProgress($user, $enrollments);

        return response()->json([
            'data' => [
                'enrolled_courses' => $enrollments->count(),
                'completed_courses' => $enrollments->whereNotNull('completed_at')->count(),
                'completed_lessons' => $user->lessonCompletions()->count(),
                'total_time_minutes' => (int) LearningActivity::where('user_id', $user->id)->sum('minutes'),
                'current_streak_days' => $stats->currentStreak($user),
                'longest_streak_days' => $stats->longestStreak($user),
                'points' => (int) $user->points,
                'activity' => $stats->activitySeries($user, 84),
                'courses' => CourseProgressResource::collection($enrollments),
            ],
        ]);
    }
}
