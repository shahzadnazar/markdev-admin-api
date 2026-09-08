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
 * This lived on DailyAttendance until attendance_records needed the same
 * lookup. Copying it would have been the fourth copy of a rule this codebase
 * has already got wrong six times; one copy is the point.
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
