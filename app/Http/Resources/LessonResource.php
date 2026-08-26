<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** @mixin \App\Models\Lesson */
class LessonResource extends LessonSummaryResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'content' => $this->content,
            'video' => $this->video ? new VideoResource($this->video) : null,
            'video_progress' => $this->whenNotNull($this->video_progress),
            'resources' => ResourceFileResource::collection($this->resources),
            'quiz_id' => $this->quiz_id ?? null,
            'assignment_id' => $this->assignment_id ?? null,
            'previous_lesson_id' => $this->previous_lesson_id ?? null,
            'next_lesson_id' => $this->next_lesson_id ?? null,
            'is_bookmarked' => (bool) ($this->is_bookmarked ?? false),
        ]);
    }
}
