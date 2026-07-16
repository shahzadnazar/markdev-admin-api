<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A quiz question as served to an in-flight attempt: options never expose
 * `is_correct`.
 *
 * @mixin \App\Models\Question
 */
class QuestionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'prompt' => $this->prompt,
            'points' => (int) $this->points,
            'position' => (int) $this->position,
            'options' => $this->options->map(fn ($option) => [
                'id' => $option->id,
                'text' => $option->text,
            ])->values(),
        ];
    }
}
