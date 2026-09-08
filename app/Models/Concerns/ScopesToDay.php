<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Rows for one calendar day, on a date-cast column.
 *
 * Not whereDate(): that compiles to date(`date`) = ?, and wrapping the column
 * in a function stops MySQL using an index on it. Not a plain equality either
 * — a date-cast attribute is written as "Y-m-d H:i:s", which a real DATE
 * column truncates but SQLite stores verbatim, so "2026-07-01 00:00:00" would
 * never equal "2026-07-01". A half-open range is right on both and still uses
 * the index.
 *
 * This lived on DailyAttendance until the per-class attendance table needed
 * the same lookup, and it stays a trait now that table is gone: the rule has
 * been got wrong seven times in this codebase, and having exactly one place
 * that states it is worth more than saving a file.
 */
trait ScopesToDay
{
    public function scopeOnDate(Builder $query, mixed $date): Builder
    {
        $day = Carbon::parse($date)->startOfDay();

        return $query
            ->where('date', '>=', $day->toDateString())
            ->where('date', '<', $day->copy()->addDay()->toDateString());
    }
}
