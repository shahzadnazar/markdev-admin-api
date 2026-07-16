<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A graded (submitted) attempt with the per-question breakdown, including
 * correct answers and explanations.
 *
 * Expects the attempt to have `quiz.course`, `quiz.questions.options` and
 * `answers` loaded.
 *
 * @mixin \App\Models\QuizAttempt
 */
class QuizResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $answers = $this->answers->keyBy('question_id');

        $score = (int) ($this->score ?? 0);
        $maxScore = (int) ($this->max_score ?? 0);

        return [
            'id' => $this->id,
            'quiz_id' => $this->quiz_id,
            'quiz_title' => $this->quiz->title,
            'course' => $this->quiz->course ? new CourseRefResource($this->quiz->course) : null,
            'started_at' => $this->started_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'score' => $score,
            'max_score' => $maxScore,
            'percent' => $maxScore > 0 ? round($score / $maxScore * 100, 2) : 0.0,
            'passed' => (bool) $this->passed,
            'questions' => $this->quiz->questions->map(function ($question) use ($answers) {
                $answer = $answers->get($question->id);

                return [
                    'id' => $question->id,
                    'type' => $question->type,
                    'prompt' => $question->prompt,
                    'points' => (int) $question->points,
                    'position' => (int) $question->position,
                    'options' => $question->options->map(fn ($option) => [
                        'id' => $option->id,
                        'text' => $option->text,
                    ])->values(),
                    'correct_option_ids' => $question->options->where('is_correct', true)->pluck('id')->values(),
                    'selected_option_ids' => collect($answer?->selected_option_ids ?? [])->map(fn ($id) => (int) $id)->values(),
                    'answer_text' => $answer?->answer_text,
                    'is_correct' => (bool) ($answer?->is_correct ?? false),
                    'points_awarded' => (int) ($answer?->points_awarded ?? 0),
                    'explanation' => $question->explanation,
                ];
            })->values(),
        ];
    }
}
