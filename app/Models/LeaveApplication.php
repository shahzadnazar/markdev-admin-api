<?php

namespace App\Models;

use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A student's leave request for a date range. Once an admin approves it,
 * every day in the range is written into the daily register as 'leave'
 * (which counts as attended); a rejected request changes nothing.
 */
class LeaveApplication extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'user_id',
        'from_date',
        'to_date',
        'reason',
        'status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /** @return array<int, Carbon> every date in [from_date, to_date] inclusive */
    public function days(): array
    {
        return collect(CarbonPeriod::create($this->from_date, $this->to_date))
            ->map(fn ($day) => Carbon::instance($day))
            ->all();
    }
}
