<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * What a task's status history and a project's status history have in common.
 *
 * Append-then-close: the current period has a null `ended_on`, and the next
 * change stamps it. Both are read the same way by StintClock, which is the
 * reason they are the same shape.
 */
trait IsStatusPeriod
{
    /** The period in force now. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_on');
    }

    /**
     * Periods whose status BEHAVES this way.
     *
     * Joined to the behaviour, never matched on a label: an admin renaming
     * "Blocked" to "Waiting on client" must not move a single figure.
     */
    public function scopeBehaving(Builder $query, string $behaviour): Builder
    {
        return $query->whereHas('status', fn (Builder $status) => $status->where('behaviour', $behaviour));
    }

    /**
     * Close this period because the status changed on `$on`.
     *
     * Ends the DAY BEFORE the change, so a day is not counted against two
     * statuses at once — floored at the period's own start, so a status set
     * and changed again the same day still reads as one day on it. A task
     * blocked for an hour was blocked that day; pretending otherwise would let
     * somebody park a task every morning for free.
     */
    public function closeOn(mixed $on): static
    {
        $end = Carbon::parse($on)->startOfDay()->subDay();
        $start = Carbon::parse($this->started_on)->startOfDay();

        $this->ended_on = static::dayKey($end->lessThan($start) ? $start : $end);
        $this->save();

        return $this;
    }
}
