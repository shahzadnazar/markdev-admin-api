<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Enrollment;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CommentController extends ApiController
{
    public function index(Request $request, Lesson $lesson): AnonymousResourceCollection
    {
        $this->authorizeLessonAccess($request, $lesson);

        $comments = $lesson->comments()
            ->whereNull('parent_id')
            ->with(['user', 'replies' => fn ($q) => $q->orderBy('created_at')->orderBy('id'), 'replies.user'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return CommentResource::collection($comments);
    }

    public function store(StoreCommentRequest $request, Lesson $lesson): JsonResponse
    {
        $this->authorizeLessonAccess($request, $lesson);

        if ($parentId = $request->input('parent_id')) {
            $parent = Comment::find($parentId);

            if ($parent === null || $parent->lesson_id !== $lesson->id) {
                throw ValidationException::withMessages([
                    'parent_id' => ['The selected comment does not belong to this lesson.'],
                ]);
            }
        }

        $comment = Comment::create([
            'lesson_id' => $lesson->id,
            'user_id' => $request->user()->id,
            'parent_id' => $parentId ?: null,
            'body' => $request->string('body')->value(),
        ]);

        $comment->load('user');

        return (new CommentResource($comment))->response($request)->setStatusCode(201);
    }

    /**
     * Edit one's own comment.
     *
     * Two separate questions, both asked: still enrolled (the course-level
     * check), and the author (the policy). Losing enrollment should not leave
     * someone able to keep editing a thread they can no longer read.
     */
    public function update(StoreCommentRequest $request, Lesson $lesson, Comment $comment): CommentResource
    {
        abort_unless($comment->lesson_id === $lesson->id, 404);
        $this->authorizeLessonAccess($request, $lesson);
        Gate::authorize('update', $comment);

        $comment->update(['body' => $request->string('body')->value()]);

        return new CommentResource($comment->load('user'));
    }

    /** Delete one's own comment. Soft delete — the table already has it. */
    public function destroy(Request $request, Lesson $lesson, Comment $comment): Response
    {
        abort_unless($comment->lesson_id === $lesson->id, 404);
        $this->authorizeLessonAccess($request, $lesson);
        Gate::authorize('delete', $comment);

        // Replies are kept: cascading a thread away because its opening line
        // was withdrawn deletes other people's words too.
        $comment->delete();

        return response()->noContent();
    }

    /**
     * Only students enrolled in the lesson's course.
     *
     * This used to read `$enrolled || $lesson->is_preview`, which meant the
     * discussion under any preview lesson was open to every signed-in user on
     * the platform — readable AND postable by people who had never enrolled.
     * A preview is a free sample of the teaching, not of the cohort, so the
     * escape hatch is gone. The lesson itself is still previewable; only its
     * discussion is now enrollment-only.
     */
    protected function authorizeLessonAccess(Request $request, Lesson $lesson): void
    {
        $enrolled = Enrollment::where('user_id', $request->user()->id)
            ->where('course_id', $lesson->course_id)
            ->exists();

        abort_unless($enrolled, 403, 'Enroll in the course to join the discussion.');
    }
}
