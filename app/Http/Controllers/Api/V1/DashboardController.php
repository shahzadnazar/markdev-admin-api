<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AnnouncementResource;
use App\Http\Resources\CourseProgressResource;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Assignment;
use App\Models\AttendanceRecord;
use App\Models\LearningActivity;
use App\Models\LeaveApplication;
use App\Models\Quiz;
use App\Services\CalendarService;
use App\Services\LearningStatsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends ApiController
{
    public function __invoke(Request $request, LearningStatsService $stats, CalendarService $calendar): JsonResponse
    {
        $user = $request->user();
        $enrolledCourseIds = $this->enrolledCourseIds($request);

        $minutesLearned = (int) LearningActivity::where('user_id', $user->id)->sum('minutes');

        $pendingAssignments = Assignment::whereIn('course_id', $enrolledCourseIds)
            ->whereDoesntHave('submissions', fn (Builder $q) => $q->where('user_id', $user->id))
            ->count();

        $pendingQuizzes = Quiz::published()
            ->whereIn('course_id', $enrolledCourseIds)
            ->whereDoesntHave('attempts', fn (Builder $q) => $q->where('user_id', $user->id)
                ->whereNotNull('submitted_at')
                ->where('passed', true))
            ->whereRaw(
                '(select count(*) from quiz_attempts where quiz_attempts.quiz_id = quizzes.id and quiz_attempts.user_id = ? and quiz_attempts.submitted_at is not null) < quizzes.attempts_allowed',
                [$user->id],
            )
            ->count();

        $attendanceTotal = AttendanceRecord::where('user_id', $user->id)->count();
        $attendancePresent = AttendanceRecord::where('user_id', $user->id)->where('status', 'present')->count();

        $continueLearning = $user->enrollments()
            ->whereNull('completed_at')
            ->with('course')
            ->orderByRaw('last_activity_at is null')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->take(4)
            ->get();
        $stats->hydrateCourseProgress($user, $continueLearning);

        $announcements = Announcement::published()
            ->where(fn (Builder $q) => $q->whereNull('course_id')->orWhereIn('course_id', $enrolledCourseIds))
            ->with(['author', 'course'])
            ->orderByDesc('published_at')
            ->take(3)
            ->get();

        $readIds = AnnouncementRead::where('user_id', $user->id)
            ->whereIn('announcement_id', $announcements->pluck('id'))
            ->pluck('announcement_id')
            ->flip();
        $announcements->each(fn (Announcement $a) => $a->setAttribute('is_read', $readIds->has($a->id)));

        return response()->json([
            'data' => [
                'stats' => [
                    'enrolled_courses' => $user->enrollments()->count(),
                    'completed_courses' => $user->enrollments()->whereNotNull('completed_at')->count(),
                    'completed_lessons' => $user->lessonCompletions()->count(),
                    'hours_learned' => round($minutesLearned / 60, 1),
                    'pending_assignments' => $pendingAssignments,
                    'pending_quizzes' => $pendingQuizzes,
                    'certificates_earned' => $user->certificates()->count(),
                    'current_streak_days' => $stats->currentStreak($user),
                    'attendance_rate' => $attendanceTotal > 0
                        ? round($attendancePresent / $attendanceTotal * 100, 1)
                        : 0,
                    'approved_leave_today' => LeaveApplication::where('user_id', $user->id)
                        ->where('status', 'approved')
                        ->whereDate('from_date', '<=', today())
                        ->whereDate('to_date', '>=', today())
                        ->exists(),
                    'pending_leaves' => LeaveApplication::where('user_id', $user->id)->pending()->count(),
                    'points' => (int) $user->points,
                    // The dashboard tile linking to Notes had no number behind
                    // it, so it read "View" among tiles that all show counts.
                    'notes_available' => \App\Models\Note::whereIn(
                        'course_id',
                        $user->enrollments()->select('course_id'),
                    )->count(),
                ],
                'continue_learning' => CourseProgressResource::collection($continueLearning),
                'upcoming' => array_slice($calendar->eventsBetween($user, now(), now()->addDays(90)), 0, 5),
                'recent_announcements' => AnnouncementResource::collection($announcements),
                'activity' => $stats->activitySeries($user, 28),
'progress' => $stats->progressSeries($user, 12),
            ],
        ]);
    }
}
