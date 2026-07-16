<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An in-flight (unsubmitted) attempt: questions without correct answers.
 *
 * @mixin \App\Models\QuizAttempt
 */
class QuizAttemptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quiz_id' => $this->quiz_id,
            'started_at' => $this->started_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'questions' => QuestionResource::collection($this->quiz->questions),
        ];
    }
}
