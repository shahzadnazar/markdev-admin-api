<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attendance row per active student per day — marked once, then only
 * correctable through the PIN-gated update flow with a written reason.
 */
class DailyAttendance extends Model
{
    use Auditable;

    /** Statuses an instructor can choose. `pending` is never one of them. */
    public const STATUSES = ['present', 'late', 'absent', 'leave'];

    /**
     * A day held open because nobody has marked it yet.
     *
     * It is deliberately absent from STATUSES and filtered out of every count,
     * so it never reaches a register, a report or a percentage. The nightly
     * close converts whatever is still pending into `absent`.
     */
    public const PENDING = 'pending';

    /**
     * What one day is worth, out of 100.
     *
     * A late arrival still attended, so it earns most of the day; approved
     * leave is neither attendance nor a mark against the student, so it sits
     * halfway; an absence earns nothing.
     */
    public const WEIGHTS = [
        'present' => 100,
        'late' => 70,
        'leave' => 50,
        'absent' => 0,
    ];

    /** Days that count toward a percentage — everything except pending. */
    public function scopeCounted(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereIn('status', self::STATUSES);
    }

    /**
     * Weighted attendance for a set of day counts, as a percentage.
     *
     * @param  array<string, int>  $counts  status => number of days
     */
    public static function weightedPercent(array $counts): ?float
    {
        $days = 0;
        $earned = 0;

        foreach (self::WEIGHTS as $status => $weight) {
            $n = (int) ($counts[$status] ?? 0);
            $days += $n;
            $earned += $n * $weight;
        }

        return $days > 0 ? round($earned / $days, 1) : null;
    }

    /** SQL that sums the weights, for computing the percentage in one query. */
    public static function weightedSumSql(string $column = 'status'): string
    {
        $cases = [];
        foreach (self::WEIGHTS as $status => $weight) {
            $cases[] = "when {$column} = '{$status}' then {$weight}";
        }

        return 'sum(case '.implode(' ', $cases).' else 0 end)';
    }

    protected $table = 'daily_attendance_records';

    protected $fillable = [
        'user_id',
        'date',
        'status',
        'remarks',
        'arrived_at',
        'source',
        'marked_by',
        'marked_at',
        'last_updated_by',
        'last_update_reason',
        'last_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'marked_at' => 'datetime',
            'last_updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_updated_by');
    }
}
