<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\LessonResource;
use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoteController extends ApiController
{
    /**
     * Notes and course resources, for courses the student is enrolled in.
     *
     * Two shapes in one list because they are one thing to a student: material
     * an instructor put there for them. A note is always an uploaded file; a
     * course resource may be a file or a link, so each row says which via
     * `kind` and the portal renders download-or-open from that.
     *
     * NOT private notes. Those are the student's own writing on the lesson
     * player, live in lesson_private_notes, and have no business on a page
     * listing what other people published — there is a test for their absence.
     *
     * Enrollment is enforced here, on the query: enrolledCourseIds() is the
     * same scoping the rest of this controller uses, so a course the student
     * left stops appearing without anything else having to remember.
     */
    public function index(Request $request): JsonResponse
    {
        $courseIds = $this->enrolledCourseIds($request);

        $resources = LessonResource::query()
            ->courseLevel()
            ->whereIn('course_id', $courseIds)
            ->with('course:id,title')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $notes = Note::query()
            ->whereIn('course_id', $courseIds)
            ->with([
                'course:id,title',
                'instructor:id,name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $items = $notes->map(fn (Note $note) => [
            'id' => $note->id,
            // The discriminator, not a prefixed id. `id` stays numeric because
            // the read endpoint takes one — a note is marked read by id, and a
            // string would have broken that silently at the call site rather
            // than here. The portal keys rows on source+id.
            'source' => 'note',
            'kind' => 'file',
            'title' => $note->title,
            'description' => $note->description,
            'url' => $note->file_url,
            'file_url' => $note->file_url,
            'file_type' => $note->file_type,
            'size_bytes' => (int) $note->size_bytes,
            'is_youtube' => false,
            'uploaded_at' => $note->created_at?->toISOString(),

            'course' => [
                'id' => $note->course?->id,
                'title' => $note->course?->title,
            ],

            'instructor' => [
                'id' => $note->instructor?->id,
                'name' => $note->instructor?->name,
            ],
        ])->concat($resources->map(fn (LessonResource $resource) => [
            'id' => $resource->id,
            'source' => 'resource',
            'kind' => $resource->kind,
            'title' => $resource->name,
            'description' => null,
            // One field the portal follows whatever the kind, so nothing
            // downstream has to know which column a resource lives in.
            'url' => $resource->target_url,
            'file_url' => $resource->file_url,
            'file_type' => $resource->file_type,
            'size_bytes' => $resource->size_bytes !== null ? (int) $resource->size_bytes : null,
            'is_youtube' => $resource->is_youtube,
            'uploaded_at' => $resource->created_at?->toISOString(),

            'course' => [
                'id' => $resource->course?->id,
                'title' => $resource->course?->title,
            ],

            // A course resource is not attributed to one instructor: it hangs
            // off the course, which may have had several.
            'instructor' => ['id' => null, 'name' => null],
        ]))
            ->sortByDesc('uploaded_at')
            ->values();

        return response()->json(['data' => $items]);
    }
    public function read(Request $request, Note $note): JsonResponse
{
    $courseIds = $this->enrolledCourseIds($request);

    abort_unless(
        $courseIds->contains($note->course_id),
        403
    );

    // Prevent counting the same note multiple times
    $read = \App\Models\NoteRead::firstOrCreate(
        [
            'user_id' => $request->user()->id,
            'note_id' => $note->id,
        ],
        [
            'read_at' => now(),
        ]
    );

    return response()->json([
        'data' => [
            'id' => $note->id,
            'is_read' => true,
            'read_at' => $read->read_at?->toISOString(),
        ],
    ]);
}
}