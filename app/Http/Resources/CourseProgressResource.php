<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The portal `CourseProgress` shape, built from an Enrollment whose
 * `completed_lessons`, `total_lessons` and `time_spent_minutes` attributes
 * were pre-computed (see LearningStatsService) and whose course is loaded.
 *
 * @mixin \App\Models\Enrollment
 */
class CourseProgressResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'course' => new CourseRefResource($this->course),
            'progress_percent' => (float) $this->progress_percent,
            'completed_lessons' => (int) ($this->completed_lessons ?? 0),
            'total_lessons' => (int) ($this->total_lessons ?? 0),
            'time_spent_minutes' => (int) ($this->time_spent_minutes ?? 0),
            'last_activity_at' => $this->last_activity_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }
}
