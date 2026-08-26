<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-student playback record for one lesson video.
 *
 * The client reports the ranges it played; this merges them into a canonical
 * set of non-overlapping segments so replays are never counted twice and a
 * seek to the end never looks like a full watch.
 */
class LessonVideoProgress extends Model
{
    protected $table = 'lesson_video_progress';

    protected $fillable = [
        'user_id',
        'lesson_id',
        'course_id',
        'duration_seconds',
        'watched_seconds',
        'furthest_seconds',
        'segments',
        'coverage_percent',
        'completed_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'segments' => 'array',
            'duration_seconds' => 'integer',
            'watched_seconds' => 'integer',
            'furthest_seconds' => 'integer',
            'coverage_percent' => 'integer',
            'completed_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** Coverage a student must reach before a video lesson counts as done. */
    public static function requiredPercent(): int
    {
        return (int) config('learning.video_required_percent', 90);
    }

    /** Ignore slivers shorter than this — they are scrubbing noise, not watching. */
    protected const MIN_SEGMENT_SECONDS = 1;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /* ------------------------------- Segments ------------------------------ */

    /**
     * Merge overlapping/adjacent ranges into ascending, non-overlapping ones.
     *
     * @param  array<int, array{0: float|int, 1: float|int}>  $ranges
     * @return array<int, array{0: int, 1: int}>
     */
    public static function mergeSegments(array $ranges, int $duration = 0): array
    {
        $clean = [];

        foreach ($ranges as $range) {
            if (! is_array($range) || count($range) < 2) {
                continue;
            }

            $start = (int) floor(min((float) $range[0], (float) $range[1]));
            $end = (int) ceil(max((float) $range[0], (float) $range[1]));

            $start = max(0, $start);
            if ($duration > 0) {
                $end = min($end, $duration);
            }

            if ($end - $start < self::MIN_SEGMENT_SECONDS) {
                continue;
            }

            $clean[] = [$start, $end];
        }

        if ($clean === []) {
            return [];
        }

        usort($clean, fn ($a, $b) => $a[0] <=> $b[0]);

        $merged = [];
        [$currentStart, $currentEnd] = $clean[0];

        foreach (array_slice($clean, 1) as [$start, $end]) {
            // Touching counts as continuous: playback ticks arrive as a stream of
            // abutting ranges, and leaving them split would inflate the count.
            if ($start <= $currentEnd) {
                $currentEnd = max($currentEnd, $end);

                continue;
            }

            $merged[] = [$currentStart, $currentEnd];
            [$currentStart, $currentEnd] = [$start, $end];
        }

        $merged[] = [$currentStart, $currentEnd];

        return $merged;
    }

    /** @param array<int, array{0: int, 1: int}> $segments */
    public static function totalSeconds(array $segments): int
    {
        return array_reduce($segments, fn (int $sum, array $s) => $sum + ($s[1] - $s[0]), 0);
    }

    /**
     * Fold newly reported ranges into what is already stored and recompute the
     * derived columns. Never lowers furthest_seconds — a rewatch from the start
     * must not erase the fact that the student once reached the end.
     *
     * @param  array<int, array{0: float|int, 1: float|int}>  $ranges
     */
    public function recordSegments(array $ranges, int $duration, ?int $position = null): void
    {
        if ($duration > 0) {
            $this->duration_seconds = $duration;
        }

        $known = $this->duration_seconds ?: 0;

        $segments = self::mergeSegments(
            array_merge($this->segments ?? [], $ranges),
            $known,
        );

        $watched = self::totalSeconds($segments);
        $furthest = $segments === [] ? 0 : max(array_column($segments, 1));

        $this->segments = $segments;
        $this->watched_seconds = $watched;
        $this->furthest_seconds = max($this->furthest_seconds ?? 0, $furthest, $position ?? 0);
        $this->coverage_percent = $known > 0
            ? (int) min(100, round($watched / $known * 100))
            : 0;
        $this->last_seen_at = now();

        if ($this->completed_at === null && $this->coverage_percent >= self::requiredPercent()) {
            $this->completed_at = now();
        }
    }

    /** Gaps between the watched segments — the parts that were skipped. */
    public function skippedSegments(): array
    {
        $duration = $this->duration_seconds ?: 0;
        $segments = $this->segments ?? [];

        if ($duration <= 0) {
            return [];
        }

        $gaps = [];
        $cursor = 0;

        foreach ($segments as [$start, $end]) {
            if ($start > $cursor) {
                $gaps[] = [$cursor, $start];
            }
            $cursor = max($cursor, $end);
        }

        if ($cursor < $duration) {
            $gaps[] = [$cursor, $duration];
        }

        return $gaps;
    }

    /** How an instructor would describe this watch at a glance. */
    public function verdict(): string
    {
        if ($this->duration_seconds <= 0 || $this->watched_seconds <= 0) {
            return 'not started';
        }

        if ($this->coverage_percent >= self::requiredPercent()) {
            return 'watched in full';
        }

        // Reaching the end while having played much less means seeking past parts.
        $reachedEnd = $this->furthest_seconds >= $this->duration_seconds * 0.9;

        return $reachedEnd ? 'skipped ahead' : 'left part-way';
    }
}
