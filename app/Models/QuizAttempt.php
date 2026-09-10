<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizAttempt extends Model
{
    protected $fillable = [
        'quiz_id',
        'user_id',
        'started_at',
        'expires_at',
        'submitted_at',
        'score',
        'max_score',
        'passed',
        'away_count',
        'away_seconds',
        'last_away_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'score' => 'integer',
            'max_score' => 'integer',
            'passed' => 'boolean',
            'away_count' => 'integer',
            'away_seconds' => 'integer',
            'last_away_at' => 'datetime',
        ];
    }

    /**
     * The shortest absence worth counting, in seconds.
     *
     * Focus leaves the window for a moment when a native control is clicked —
     * the address bar, a permission prompt, a file dialog. Counting those
     * would inflate the number with things that are not leaving the quiz, and
     * an inflated number is worse than no number: the whole point is that six
     * switches should mean something.
     */
    public const MIN_AWAY_SECONDS = 1;

    /* ------------------------------ Relations ------------------------------ */

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class);
    }

    /* ------------------------------- Activity ------------------------------ */

    /**
     * The window this attempt could plausibly have been running for.
     *
     * Its own start to now, or to its expiry if that has passed — an attempt
     * cannot be sat after it expires, so seconds claimed beyond that are not
     * time anyone spent away from it.
     */
    public function elapsedSeconds(): int
    {
        if ($this->started_at === null) {
            return 0;
        }

        $ceiling = now();

        if ($this->expires_at !== null && $this->expires_at->lt($ceiling)) {
            $ceiling = $this->expires_at;
        }

        return max(0, (int) $this->started_at->diffInSeconds($ceiling));
    }

    /**
     * Record CUMULATIVE away totals reported by the client.
     *
     * Cumulative and monotonic on purpose, and that is what makes a retry
     * safe: the client sends its running totals every time, the server keeps
     * whichever is larger, so the same message arriving twice changes nothing
     * and a message lost in flight is repaired by the next one. Deltas would
     * double-count on every retry, which is exactly what an unloading tab
     * makes likely.
     *
     * Both numbers are capped against the attempt's own elapsed time. A
     * student can under-report by sending nothing — that cannot be prevented
     * from the client side and is not what this defends against. What it does
     * prevent is a tampered client writing figures that make the column
     * nonsense: nobody can be away for longer than their attempt has existed,
     * and no round trip is shorter than MIN_AWAY_SECONDS, so the count has a
     * ceiling too.
     */
    public function recordAwayTotals(int $count, int $seconds): void
    {
        $elapsed = $this->elapsedSeconds();

        $seconds = max(0, min($seconds, $elapsed));
        $count = max(0, min($count, intdiv($elapsed, self::MIN_AWAY_SECONDS)));

        $next = [
            'away_count' => max((int) $this->away_count, $count),
            'away_seconds' => max((int) $this->away_seconds, $seconds),
        ];

        if ($next['away_count'] > (int) $this->away_count) {
            $next['last_away_at'] = now();
        }

        $this->forceFill($next)->save();
    }

    /**
     * The instructor's one line, or null when there is nothing to say.
     *
     * Null at zero rather than "0 times": a row reading zero invites an
     * instructor to read meaning into the silence of every attempt taken
     * before this was measured at all.
     */
    public function awaySummary(): ?string
    {
        $count = (int) $this->away_count;

        if ($count < 1) {
            return null;
        }

        $times = $count === 1 ? 'once' : $count.' times';

        return 'Left the tab '.$times.' · '.$this->awayDuration().' away';
    }

    /** "45s", "4m 12s", "1h 2m" — the same shape the quiz clock uses. */
    public function awayDuration(): string
    {
        $seconds = max(0, (int) $this->away_seconds);

        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        if ($minutes < 60) {
            return $rest === 0 ? $minutes.'m' : $minutes.'m '.$rest.'s';
        }

        $hours = intdiv($minutes, 60);
        $restMinutes = $minutes % 60;

        return $restMinutes === 0 ? $hours.'h' : $hours.'h '.$restMinutes.'m';
    }
}
