<?php

namespace App\Services;

use App\Models\PointEvent;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class QuizService
{
    public const POINTS_QUIZ_PASSED = 20;

    /**
     * Starts a new attempt or resumes the student's open one (refreshing its
     * time window).
     */
    public function startAttempt(User $user, Quiz $quiz): QuizAttempt
    {
        $now = now();

        if (($quiz->available_from !== null && $now->lt($quiz->available_from))
            || ($quiz->available_until !== null && $now->gt($quiz->available_until))) {
            throw ValidationException::withMessages([
                'quiz' => ['This quiz is not currently available.'],
            ]);
        }

        $expiresAt = $quiz->time_limit_minutes ? $now->copy()->addMinutes($quiz->time_limit_minutes) : null;

        $open = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('user_id', $user->id)
            ->whereNull('submitted_at')
            ->latest('started_at')
            ->first();

        if ($open !== null) {
            $open->update(['expires_at' => $expiresAt]);

            return $open;
        }

        $finished = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('user_id', $user->id)
            ->whereNotNull('submitted_at')
            ->count();

        if ($finished >= $quiz->attempts_allowed) {
            throw ValidationException::withMessages([
                'quiz' => ['You have no attempts remaining for this quiz.'],
            ]);
        }

        return QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'user_id' => $user->id,
            'started_at' => $now,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Grades and finalizes an attempt.
     *
     * @param  array<int, array{question_id: int, selected_option_ids?: array<int>, answer_text?: string|null}>  $answers
     */
    public function submitAttempt(Quiz $quiz, QuizAttempt $attempt, array $answers): QuizAttempt
    {
        if ($attempt->submitted_at !== null) {
            throw ValidationException::withMessages([
                'attempt' => ['This attempt has already been submitted.'],
            ]);
        }

        $quiz->load(['questions.options']);

        $submitted = collect($answers)->keyBy(fn (array $answer) => (int) $answer['question_id']);

        $score = 0;
        $maxScore = 0;

        foreach ($quiz->questions as $question) {
            $maxScore += (int) $question->points;

            $answer = $submitted->get($question->id, []);
            $optionIds = $question->options->pluck('id');
            $selected = collect($answer['selected_option_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $optionIds->contains($id))
                ->unique()
                ->values();
            $answerText = isset($answer['answer_text']) && $answer['answer_text'] !== '' ? (string) $answer['answer_text'] : null;

            [$isCorrect, $pointsAwarded] = $this->grade($question->type, (int) $question->points, $selected, $question->options);

            $score += $pointsAwarded;

            QuizAnswer::create([
                'quiz_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'selected_option_ids' => $selected->all(),
                'answer_text' => $answerText,
                'is_correct' => $isCorrect,
                'points_awarded' => $pointsAwarded,
            ]);
        }

        $passingScore = (int) $quiz->passing_score;
        $percent = $maxScore > 0 ? $score / $maxScore * 100 : 0.0;
        $passed = $percent >= $passingScore;

        $attempt->update([
            'submitted_at' => now(),
            'score' => $score,
            'max_score' => $maxScore,
            'passed' => $passed,
        ]);

        if ($passed) {
            PointEvent::create([
                'user_id' => $attempt->user_id,
                'points' => self::POINTS_QUIZ_PASSED,
                'reason' => "Passed quiz: {$quiz->title}",
                'created_at' => now(),
            ]);
            $attempt->user->increment('points', self::POINTS_QUIZ_PASSED);
        }

        return $attempt;
    }

    /**
     * @param  Collection<int, int>  $selected
     * @param  \Illuminate\Database\Eloquent\Collection<int, \App\Models\QuestionOption>  $options
     * @return array{0: bool, 1: int}
     */
    protected function grade(string $type, int $points, Collection $selected, $options): array
    {
        $correctIds = $options->where('is_correct', true)->pluck('id')->sort()->values();

        $isCorrect = match ($type) {
            // Correct iff exactly the single correct option was selected.
            'single_choice', 'true_false' => $selected->count() === 1
                && $correctIds->count() >= 1
                && $correctIds->contains($selected->first()),
            // All-or-nothing: the selected set must equal the correct set.
            'multiple_choice' => $correctIds->isNotEmpty()
                && $selected->sort()->values()->all() === $correctIds->all(),
            // Manual grading later; never auto-correct.
            'short_answer' => false,
            default => false,
        };

        return [$isCorrect, $isCorrect ? $points : 0];
    }
}
