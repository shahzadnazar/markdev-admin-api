<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Lesson */
class LessonSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'module_id' => $this->module_id,
            'course_id' => $this->course_id,
            'title' => $this->title,
            'type' => $this->type,
            'duration_minutes' => (int) $this->duration_minutes,
            'position' => (int) $this->position,
            'is_preview' => (bool) $this->is_preview,
            'is_completed' => (bool) ($this->is_completed ?? false),
        ];
    }
}
