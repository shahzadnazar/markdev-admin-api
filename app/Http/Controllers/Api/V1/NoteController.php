<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoteController extends ApiController
{
    /**
     * Return notes belonging to courses the authenticated student is enrolled in.
     */
    public function index(Request $request): JsonResponse
    {
        $courseIds = $this->enrolledCourseIds($request);

        $notes = Note::query()
            ->whereIn('course_id', $courseIds)
            ->with([
                'course:id,title',
                'instructor:id,name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $notes->map(fn (Note $note) => [
                'id' => $note->id,
                'title' => $note->title,
                'description' => $note->description,
                'file_url' => $note->file_url,
                'file_type' => $note->file_type,
                'size_bytes' => (int) $note->size_bytes,
                'uploaded_at' => $note->created_at?->toISOString(),

                'course' => [
                    'id' => $note->course?->id,
                    'title' => $note->course?->title,
                ],

                'instructor' => [
                    'id' => $note->instructor?->id,
                    'name' => $note->instructor?->name,
                ],
            ])->values(),
        ]);
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