<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\LessonResource;
use App\Models\MaterialRead;
use App\Services\LessonProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Study materials: every file attached to a lesson in the student's enrolled
 * courses, flattened into one browsable, searchable list with read receipts.
 */
class MaterialController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $courseIds = $this->enrolledCourseIds($request);

        $resources = LessonResource::query()
            ->whereHas('lesson', fn ($query) => $query->whereIn('course_id', $courseIds))
            ->with(['lesson:id,title,type,course_id', 'lesson.course:id,title'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $readIds = MaterialRead::where('user_id', $request->user()->id)
            ->whereIn('lesson_resource_id', $resources->pluck('id'))
            ->pluck('lesson_resource_id')
            ->flip();

        return response()->json([
            'data' => $resources->map(fn (LessonResource $resource) => [
                'id' => $resource->id,
                'name' => $resource->name,
                'file_url' => $resource->file_url,
                'file_type' => $resource->file_type,
                'size_bytes' => (int) $resource->size_bytes,
                'uploaded_at' => $resource->created_at?->toISOString(),
                'is_read' => $readIds->has($resource->id),
                'lesson' => [
                    'id' => $resource->lesson?->id,
                    'title' => $resource->lesson?->title,
                    'type' => $resource->lesson?->type,
                ],
                'course' => [
                    'id' => $resource->lesson?->course?->id,
                    'title' => $resource->lesson?->course?->title,
                ],
            ])->values(),
        ]);
    }

    public function read(
    Request $request,
    LessonResource $resource,
    LessonProgressService $progress,
): JsonResponse {
    $resource->load('lesson');

    abort_unless(
        $resource->lesson !== null
            && $this->enrolledCourseIds($request)->contains($resource->lesson->course_id),
        403,
    );

    // Let LessonProgressService handle MaterialRead creation,
    // learning minutes, and lesson completion.
    $progress->recordMaterialRead(
        $request->user(),
        $resource
    );

    $read = MaterialRead::where('user_id', $request->user()->id)
        ->where('lesson_resource_id', $resource->id)
        ->first();

    return response()->json([
        'data' => [
            'id' => $resource->id,
            'is_read' => true,
            'read_at' => $read?->read_at?->toISOString(),
        ],
    ]);
}
}
