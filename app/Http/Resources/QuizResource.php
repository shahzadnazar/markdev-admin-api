<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Quiz */
class QuizResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course' => new CourseRefResource($this->course),
            'lesson_id' => $this->lesson_id,
            'title' => $this->title,
            'description' => $this->description,
            'time_limit_minutes' => $this->time_limit_minutes !== null ? (int) $this->time_limit_minutes : null,
            'attempts_allowed' => (int) $this->attempts_allowed,
            'attempts_used' => (int) ($this->attempts_used ?? 0),
            'questions_count' => (int) ($this->questions_count ?? 0),
            'total_points' => (int) ($this->total_points ?? 0),
            'passing_score' => (int) $this->passing_score,
            'status' => $this->status ?? 'not_started',
            'best_score' => $this->best_score !== null ? (float) $this->best_score : null,
            'available_from' => $this->available_from?->toISOString(),
            'available_until' => $this->available_until?->toISOString(),
        ];
    }
}
