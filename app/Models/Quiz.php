<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\QuizRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quiz extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'course_id',
        'lesson_id',
        'title',
        'description',
        'seconds_per_question',
        'attempts_allowed',
        'passing_score',
        'available_from',
        'available_until',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'seconds_per_question' => 'integer',
            'attempts_allowed' => 'integer',
            'passing_score' => 'integer',
            'available_from' => 'datetime',
            'available_until' => 'datetime',
            'is_published' => 'boolean',
        ];
    }

    /* ------------------------------ Relations ------------------------------ */

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /* ------------------------------ The rules ------------------------------ */

    /**
     * Attempts this quiz allows: its own override, or the academy default.
     *
     * NULL on the column is not "unlimited" — it means "follow the setting".
     * The old form hint claimed blank meant unlimited, which was never true:
     * the service compared `$finished >= $quiz->attempts_allowed`, and in PHP
     * `0 >= null` is true, so a blank field refused the very first attempt.
     */
    public function allowedAttempts(): int
    {
        return QuizRules::attemptsFor($this);
    }

    /** Seconds each question is worth here. */
    public function secondsPerQuestion(): int
    {
        return QuizRules::secondsPerQuestionFor($this);
    }

    /**
     * The whole clock for one attempt, in seconds.
     *
     * Derived, never stored: add a question in the builder and the quiz gains
     * time on its own, which a saved total could not do.
     */
    public function timeLimitSeconds(?int $questionCount = null): int
    {
        return QuizRules::totalSecondsFor($this, $questionCount ?? $this->questions_count);
    }

    /* ------------------------------- Scopes -------------------------------- */

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}
