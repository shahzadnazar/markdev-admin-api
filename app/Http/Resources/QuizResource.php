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
            // The RESOLVED rules, not the raw columns. NULL on a column means
            // "follow the academy default", and the portal has no business
            // knowing that — it should be told what this quiz actually gives.
            'seconds_per_question' => $this->secondsPerQuestion(),
            'time_limit_seconds' => $this->timeLimitSeconds((int) ($this->questions_count ?? 0)),
            'attempts_allowed' => $this->allowedAttempts(),
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
