<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\LessonResource;
use App\Models\Bookmark;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LearningActivity;
use App\Models\Lesson;
use App\Models\LessonCompletion;
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

        return new LessonResource($lesson);
    }

    public function complete(Request $request, Course $course, Lesson $lesson, LessonProgressService $progress): JsonResponse
    {
        $percent = $progress->complete($request->user(), $course, $lesson);

        return response()->json(['data' => ['progress_percent' => $percent]]);
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
