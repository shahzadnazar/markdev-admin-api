<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2'],
        ]);

        $term = trim((string) $request->query('q'));
        $like = "%{$term}%";
        $enrolledCourseIds = $this->enrolledCourseIds($request);

        $courses = Course::published()
            ->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('excerpt', 'like', $like))
            ->orderBy('title')
            ->take(5)
            ->get()
            ->map(fn (Course $course) => [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
                'thumbnail_url' => $course->thumbnail_url,
                'excerpt' => $course->excerpt,
            ]);

        $lessons = Lesson::whereIn('course_id', $enrolledCourseIds)
            ->where('title', 'like', $like)
            ->with('course')
            ->orderBy('title')
            ->take(5)
            ->get()
            ->map(fn (Lesson $lesson) => [
                'id' => $lesson->id,
                'course_id' => $lesson->course_id,
                'title' => $lesson->title,
                'course_title' => $lesson->course?->title,
            ]);

        $assignments = Assignment::whereIn('course_id', $enrolledCourseIds)
            ->where('title', 'like', $like)
            ->with('course')
            ->orderBy('title')
            ->take(5)
            ->get()
            ->map(fn (Assignment $assignment) => [
                'id' => $assignment->id,
                'title' => $assignment->title,
                'course_title' => $assignment->course?->title,
                'due_at' => $assignment->due_at?->toISOString(),
            ]);

        $quizzes = Quiz::published()
            ->whereIn('course_id', $enrolledCourseIds)
            ->where('title', 'like', $like)
            ->with('course')
            ->orderBy('title')
            ->take(5)
            ->get()
            ->map(fn (Quiz $quiz) => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'course_title' => $quiz->course?->title,
            ]);

        $announcements = Announcement::published()
            ->where(fn (Builder $q) => $q->whereNull('course_id')->orWhereIn('course_id', $enrolledCourseIds))
            ->where('title', 'like', $like)
            ->orderByDesc('published_at')
            ->take(5)
            ->get()
            ->map(fn (Announcement $announcement) => [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'published_at' => $announcement->published_at?->toISOString(),
            ]);

        return response()->json([
            'data' => [
                'courses' => $courses,
                'lessons' => $lessons,
                'assignments' => $assignments,
                'quizzes' => $quizzes,
                'announcements' => $announcements,
            ],
        ]);
    }
}
