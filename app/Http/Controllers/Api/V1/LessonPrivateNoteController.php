<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonPrivateNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A student's private notebook for one lesson.
 *
 * There is no policy class here on purpose. A policy answers "may this user
 * act on THAT record", which invites fetching someone else's row and then
 * asking — and a policy that is forgotten at one call site leaks. Every query
 * below is scoped by user_id in the WHERE clause instead, so another student's
 * note is not denied, it is not found: there is no code path that loads one.
 *
 * No index-across-students on THIS controller, and no resource that embeds a
 * note in a lesson payload — deliberate absences, both of them.
 *
 * A super-admin does have a read-only oversight path elsewhere
 * (Admin\PrivateNoteController), which audits every note it opens. Nothing in
 * this controller serves it: a student's endpoints stay scoped to the student,
 * so widening oversight later cannot be done by loosening a WHERE clause here.
 */
class LessonPrivateNoteController extends ApiController
{
    /** The student's own note, or an empty one they have not written yet. */
    public function show(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorizeLessonAccess($request, $lesson);

        $note = $this->ownNote($request, $lesson);

        return response()->json(['data' => [
            'lesson_id' => $lesson->id,
            'body' => $note?->body ?? '',
            'updated_at' => $note?->updated_at?->toISOString(),
        ]]);
    }

    /**
     * Write the note.
     *
     * An upsert on (lesson_id, user_id), which the unique index makes safe
     * under a double-submit. An empty body deletes it rather than storing a
     * blank row — clearing the box means "I have no notes here".
     */
    public function store(Request $request, Lesson $lesson): JsonResponse|Response
    {
        $this->authorizeLessonAccess($request, $lesson);

        $data = $request->validate([
            // nullable because Laravel's ConvertEmptyStringsToNull turns a
            // cleared textarea into null before validation ever sees it —
            // and clearing the box is how a student says "no notes here".
            'body' => ['present', 'nullable', 'string', 'max:20000'],
        ]);

        $body = trim((string) ($data['body'] ?? ''));

        if ($body === '') {
            $this->ownNote($request, $lesson)?->delete();

            return response()->noContent();
        }

        $note = LessonPrivateNote::updateOrCreate(
            ['lesson_id' => $lesson->id, 'user_id' => $request->user()->id],
            ['body' => $body],
        );

        return response()->json(['data' => [
            'lesson_id' => $lesson->id,
            'body' => $note->body,
            'updated_at' => $note->updated_at?->toISOString(),
        ]]);
    }

    /** Scoped in the WHERE clause, never fetched and then checked. */
    protected function ownNote(Request $request, Lesson $lesson): ?LessonPrivateNote
    {
        return LessonPrivateNote::where('lesson_id', $lesson->id)
            ->where('user_id', $request->user()->id)
            ->first();
    }

    /** Same rule as the discussion: enrolled students of this course only. */
    protected function authorizeLessonAccess(Request $request, Lesson $lesson): void
    {
        $enrolled = Enrollment::where('user_id', $request->user()->id)
            ->where('course_id', $lesson->course_id)
            ->exists();

        abort_unless($enrolled, 403, 'Enroll in the course to keep notes on it.');
    }
}
