<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Enrollment;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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

    protected function authorizeLessonAccess(Request $request, Lesson $lesson): void
    {
        $enrolled = Enrollment::where('user_id', $request->user()->id)
            ->where('course_id', $lesson->course_id)
            ->exists();

        abort_unless($enrolled || $lesson->is_preview, 403, 'Enroll in the course to join the discussion.');
    }
}
