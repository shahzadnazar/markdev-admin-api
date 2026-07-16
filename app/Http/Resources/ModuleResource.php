<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Module */
class ModuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'title' => $this->title,
            'position' => (int) $this->position,
            'duration_minutes' => (int) $this->lessons->sum('duration_minutes'),
            'lessons_count' => $this->lessons->count(),
            'lessons' => LessonSummaryResource::collection($this->lessons),
        ];
    }
}
