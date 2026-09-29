<?php

namespace App\Models;

use App\Support\DeliveryScore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cached delivery score for one person — LIST SCREENS ONLY.
 *
 * Exactly what enrollments.progress_percent is to course progress. Any screen
 * about one person computes live through DeliveryScoreCalculator and never
 * reads this row; a team page listing twelve members reads twelve of these
 * instead of running twelve calculations.
 *
 * The counts live here beside the percentage because the score is never
 * rendered alone.
 */
class DeliveryScoreRecord extends Model
{
    protected $table = 'delivery_scores';

    protected $fillable = [
        'user_id',
        'percent',
        'stints_completed',
        'late_count',
        'days_over',
        'blocked_days',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'percent' => 'integer',
            'stints_completed' => 'integer',
            'late_count' => 'integer',
            'days_over' => 'integer',
            'blocked_days' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The shape the score component takes, from a cached row. */
    public function toScoreArray(): array
    {
        return [
            'percent' => $this->percent,
            'stints_completed' => $this->stints_completed,
            'late_count' => $this->late_count,
            'days_over' => $this->days_over,
            'blocked_days' => $this->blocked_days,
            'minimum' => DeliveryScore::minimumStints(),
        ];
    }
}
