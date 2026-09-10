<?php

namespace App\Support;

use App\Models\Quiz;
use App\Models\Setting;

/**
 * How long a quiz runs for, and how many goes a student gets.
 *
 * Both are academy-wide settings with a per-quiz override. A quiz storing NULL
 * follows the academy default and keeps following it when an admin changes it;
 * a quiz storing a number has been deliberately pinned, because a final exam
 * may reasonably differ from a practice quiz. That is the same shape as every
 * other setting here — LeaveAllowance, AbsenceFine, AttendanceWeights — which
 * read live through Setting::cached() rather than snapshotting a value at
 * creation.
 *
 * The time limit is SECONDS PER QUESTION, derived at run time, not a whole-quiz
 * total stored once. Questions are added and removed by QuestionController,
 * which never touches the quiz row, so a stored total would go stale the moment
 * the builder was used — a 10-question quiz that grew to 12 would still be
 * running a 10-question clock with nothing to show anything was wrong.
 *
 * Setting::cached() is an in-request memo over one SELECT. Deliberately not
 * Cache::remember: that once put cache-table WRITES into a render path here
 * and cost a 30-second timeout.
 */
class QuizRules
{
    /** What a fresh install allows, before an admin saves anything. */
    public const DEFAULT_ATTEMPTS = 1;

    public const DEFAULT_SECONDS_PER_QUESTION = 30;

    /** The bounds the settings form enforces, kept here so the clamps agree. */
    public const MIN_ATTEMPTS = 1;

    public const MAX_ATTEMPTS = 10;

    public const MIN_SECONDS_PER_QUESTION = 5;

    public const MAX_SECONDS_PER_QUESTION = 600;

    /** Attempts a quiz allows when it does not say otherwise. */
    public static function defaultAttempts(): int
    {
        return static::clamp(
            (int) (Setting::cached('quiz_default_attempts') ?? self::DEFAULT_ATTEMPTS),
            self::MIN_ATTEMPTS,
            self::MAX_ATTEMPTS,
        );
    }

    /** Seconds each question is worth when a quiz does not say otherwise. */
    public static function defaultSecondsPerQuestion(): int
    {
        return static::clamp(
            (int) (Setting::cached('quiz_seconds_per_question') ?? self::DEFAULT_SECONDS_PER_QUESTION),
            self::MIN_SECONDS_PER_QUESTION,
            self::MAX_SECONDS_PER_QUESTION,
        );
    }

    /** What THIS quiz allows: its own override, or the academy default. */
    public static function attemptsFor(Quiz $quiz): int
    {
        return $quiz->attempts_allowed !== null
            ? max(self::MIN_ATTEMPTS, (int) $quiz->attempts_allowed)
            : static::defaultAttempts();
    }

    /** What THIS quiz gives per question: its own override, or the default. */
    public static function secondsPerQuestionFor(Quiz $quiz): int
    {
        return $quiz->seconds_per_question !== null
            ? max(self::MIN_SECONDS_PER_QUESTION, (int) $quiz->seconds_per_question)
            : static::defaultSecondsPerQuestion();
    }

    /**
     * The whole clock for one attempt, in seconds.
     *
     * Pass $questionCount where it is already known — the API list has it from
     * withCount and asking again would be a query per quiz. A quiz with no
     * questions still gets one question's worth rather than a zero-second
     * attempt that expires before it is rendered.
     */
    public static function totalSecondsFor(Quiz $quiz, ?int $questionCount = null): int
    {
        $questions = $questionCount ?? $quiz->questions()->count();

        return max(1, $questions) * static::secondsPerQuestionFor($quiz);
    }

    /**
     * A whole-quiz clock worded for a human: "90s", "5m", "5m 30s".
     *
     * Seconds are kept below two minutes because "1m 30s" is what a student
     * needs to hear for a short quiz; above that the seconds are noise unless
     * they are non-zero.
     */
    public static function humanTotal(int $seconds): string
    {
        if ($seconds < 120) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return $rest === 0 ? $minutes.'m' : $minutes.'m '.$rest.'s';
    }

    protected static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
