<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\LessonResource;
use App\Models\Bookmark;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LearningActivity;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\LessonVideoProgress;
use App\Services\LessonProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class LessonController extends ApiController
{
    public function show(Request $request, Course $course, Lesson $lesson): LessonResource
    {
        abort_unless($course->status === 'published', 404);

        $user = $request->user();

        $enrolled = Enrollment::where('user_id', $user->id)->where('course_id', $course->id)->exists();

        abort_unless($enrolled || $lesson->is_preview, 403, 'Enroll in the course to access this lesson.');

        $lesson->load(['video', 'resources']);

        [$previousId, $nextId] = $this->neighbours($course, $lesson);

        $lesson->setAttribute('quiz_id', $lesson->quiz()->value('id'));
        $lesson->setAttribute('assignment_id', $lesson->assignment()->value('id'));
        $lesson->setAttribute('previous_lesson_id', $previousId);
        $lesson->setAttribute('next_lesson_id', $nextId);
        $lesson->setAttribute('is_bookmarked', Bookmark::where('user_id', $user->id)
            ->where('bookmarkable_type', Lesson::class)
            ->where('bookmarkable_id', $lesson->id)
            ->exists());
        $lesson->setAttribute('is_completed', LessonCompletion::where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->exists());
        $lesson->setAttribute('video_progress', $this->videoProgressPayload($user->id, $lesson));

        return new LessonResource($lesson);
    }

    public function complete(Request $request, Course $course, Lesson $lesson, LessonProgressService $progress): JsonResponse
    {
        $user = $request->user();

        // A video lesson is only done once enough of it has actually been played.
        // Seeking past a section never counts, so the check cannot be satisfied
        // by dragging the scrubber to the end.
        if ($lesson->type === 'video' && $lesson->video()->exists()) {
            $required = LessonVideoProgress::requiredPercent();
            $watch = LessonVideoProgress::where('user_id', $user->id)
                ->where('lesson_id', $lesson->id)
                ->first();
            $covered = (int) ($watch->coverage_percent ?? 0);

            if ($covered < $required) {
                return response()->json([
                    'message' => "Watch at least {$required}% of the video to complete this lesson. You have watched {$covered}%.",
                    'errors' => ['video' => ["You have watched {$covered}% of {$required}%."]],
                    'data' => ['coverage_percent' => $covered, 'required_percent' => $required],
                ], 422);
            }
        }

        $percent = $progress->complete($user, $course, $lesson);

        return response()->json(['data' => ['progress_percent' => $percent]]);
    }

    /**
     * Record the ranges of the lesson video the student just played.
     *
     * The client sends the segments it observed; the server merges them into
     * what it already holds, so coverage accumulates across sittings and a
     * replay is never counted twice.
     */
    public function videoProgress(Request $request, Course $course, Lesson $lesson): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'duration' => ['required', 'integer', 'min:1', 'max:86400'],
            'position' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'segments' => ['present', 'array', 'max:500'],
            'segments.*' => ['array', 'size:2'],
            'segments.*.*' => ['numeric', 'min:0', 'max:86400'],
        ]);

        $enrolled = Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->exists();

        abort_unless($enrolled || $lesson->is_preview, 403, 'Enroll in the course to access this lesson.');
        abort_unless($lesson->course_id === $course->id, 404);

        $progress = LessonVideoProgress::firstOrNew([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
        ]);
        $progress->course_id = $course->id;
        $progress->recordSegments($data['segments'], $data['duration'], $data['position'] ?? null);
        $progress->save();

        return response()->json(['data' => $this->videoProgressPayload($user->id, $lesson, $progress)]);
    }

    /** @return array<string, mixed> */
    protected function videoProgressPayload(int $userId, Lesson $lesson, ?LessonVideoProgress $progress = null): array
    {
        $required = LessonVideoProgress::requiredPercent();

        $progress ??= LessonVideoProgress::where('user_id', $userId)
            ->where('lesson_id', $lesson->id)
            ->first();

        return [
            'watched_seconds' => (int) ($progress->watched_seconds ?? 0),
            'duration_seconds' => (int) ($progress->duration_seconds ?? 0),
            'furthest_seconds' => (int) ($progress->furthest_seconds ?? 0),
            'coverage_percent' => (int) ($progress->coverage_percent ?? 0),
            'required_percent' => $required,
            'can_complete' => (int) ($progress->coverage_percent ?? 0) >= $required,
        ];
    }

    public function uncomplete(Request $request, Course $course, Lesson $lesson, LessonProgressService $progress): JsonResponse
    {
        $percent = $progress->uncomplete($request->user(), $course, $lesson);

        return response()->json(['data' => ['progress_percent' => $percent]]);
    }

    public function activity(
    Request $request,
    Course $course,
    Lesson $lesson
): JsonResponse {
    $user = $request->user();

    $request->validate([
        'minutes' => ['required', 'integer', 'min:1', 'max:120'],
    ]);

    $enrolled = Enrollment::where('user_id', $user->id)
        ->where('course_id', $course->id)
        ->exists();

    abort_unless(
        $enrolled || $lesson->is_preview,
        403,
        'Enroll in the course to access this lesson.'
    );

    LearningActivity::updateOrCreate(
        [
            'user_id' => $user->id,
            'date' => now()->toDateString(),
        ],
        [
            'minutes' => DB::raw(
                'minutes + ' . (int) $request->integer('minutes')
            ),
        ]
    );

    return response()->json([
        'data' => [
            'success' => true,
        ],
    ]);
}
    /**
     * Previous/next lesson ids ordered by module position, then lesson
     * position, across the whole course.
     *
     * @return array{0: int|null, 1: int|null}
     */
    protected function neighbours(Course $course, Lesson $lesson): array
    {
        $ordered = Lesson::query()
            ->where('lessons.course_id', $course->id)
            ->join('modules', 'modules.id', '=', 'lessons.module_id')
            ->whereNull('modules.deleted_at')
            ->orderBy('modules.position')
            ->orderBy('lessons.position')
            ->orderBy('lessons.id')
            ->pluck('lessons.id')
            ->values();

        $index = $ordered->search($lesson->id);

        if ($index === false) {
            return [null, null];
        }

        return [
            $index > 0 ? $ordered[$index - 1] : null,
            $index < $ordered->count() - 1 ? $ordered[$index + 1] : null,
        ];
    }
}
