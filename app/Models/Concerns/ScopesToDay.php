<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The ONE sanctioned way to query a date-cast column.
 *
 * WHY THIS EXISTS. A `date`-cast attribute is written through the connection's
 * datetime format, so the same row stores "2026-09-11 00:00:00" on SQLite and
 * "2026-09-11" on MySQL, whose DATE column truncates it. An equality lookup
 * therefore MATCHES ON ONE DRIVER AND MISSES ON THE OTHER — and the tests run
 * on the driver where it silently passes. That has cost this project ten bugs:
 * a wrong absence fine, duplicate register rows, a test that passed while the
 * bug it covered was live, a security hole no test could reach, a crashed
 * seeding script, and a bulk mark-present that threw a unique violation.
 *
 * Not whereDate() either: date(`date`) = ? is correct on both drivers but wraps
 * the column in a function, so MySQL cannot use the index on it. A half-open
 * range is right on both AND keeps the index.
 *
 * PINNING THE CAST IS NOT ENOUGH, and it was measured rather than assumed.
 * With `date:Y-m-d` both drivers store "2026-09-11", so a STRING equality
 * works — but `where('date', today())` still returns nothing, because a Carbon
 * binds as "Y-m-d H:i:s" whatever the attribute cast says, and `today()` is the
 * most natural thing to reach for. The casts are pinned anyway (it removes one
 * of the two failure modes and makes the drivers agree), and these scopes
 * remain the only form, because they are the only one that is right in every
 * case.
 *
 * ALL DAYS ARE RESOLVED IN THE APP TIMEZONE (Asia/Karachi), never UTC — a day
 * boundary an hour out is the same class of bug wearing a different hat.
 *
 * Enforced by DateColumnGuardTest, which derives the guarded columns from the
 * models' own casts and fails by name when another form appears anywhere in
 * app/, database/ or tests/.
 */
trait ScopesToDay
{
    /**
     * The date-cast column these scopes address.
     *
     * Overridable because not every table calls it `date` — LeaveApplication
     * has from_date and to_date, and passes the column per call instead.
     */
    public static function dayColumn(): string
    {
        return 'date';
    }

    /**
     * The canonical stored value for a day: "Y-m-d", in the app timezone.
     *
     * Use this for anything that WRITES a date-cast column, and for building
     * the match array of a create. A Carbon handed to the query builder
     * serialises with a time and will not match.
     */
    public static function dayKey(mixed $date): string
    {
        return Carbon::parse($date)->startOfDay()->toDateString();
    }

    /** Rows on one calendar day. */
    public function scopeOnDate(Builder $query, mixed $date, ?string $column = null): Builder
    {
        $column ??= static::dayColumn();
        $day = Carbon::parse($date)->startOfDay();

        return $query
            ->where($column, '>=', $day->toDateString())
            ->where($column, '<', $day->copy()->addDay()->toDateString());
    }

    /**
     * Rows on any of several days — the whereIn replacement.
     *
     * whereIn on a date-cast column has the same fault as equality, once per
     * value. Expressed as a group of day ranges so it stays index-friendly and
     * correct on both drivers.
     */
    public function scopeOnDates(Builder $query, iterable $dates, ?string $column = null): Builder
    {
        $days = collect($dates)->map(fn ($date) => static::dayKey($date))->unique()->values();

        if ($days->isEmpty()) {
            // An empty selection matches nothing, which is what whereIn([])
            // does; saying so explicitly beats returning every row.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $group) use ($days, $column) {
            foreach ($days as $day) {
                $group->orWhere(fn (Builder $one) => $one->onDate($day, $column));
            }
        });
    }

    /** Rows from the start of $from to the END of $to — inclusive both ends. */
    public function scopeBetweenDates(Builder $query, mixed $from, mixed $to, ?string $column = null): Builder
    {
        $column ??= static::dayColumn();

        return $query
            ->where($column, '>=', Carbon::parse($from)->startOfDay()->toDateString())
            ->where($column, '<', Carbon::parse($to)->startOfDay()->addDay()->toDateString());
    }

    /** Rows from the start of this day onward. */
    public function scopeFromDate(Builder $query, mixed $date, ?string $column = null): Builder
    {
        $column ??= static::dayColumn();

        return $query->where($column, '>=', Carbon::parse($date)->startOfDay()->toDateString());
    }

    /** Rows strictly before this day begins. */
    public function scopeBeforeDate(Builder $query, mixed $date, ?string $column = null): Builder
    {
        $column ??= static::dayColumn();

        return $query->where($column, '<', Carbon::parse($date)->startOfDay()->toDateString());
    }

    /** Rows up to and including this whole day. */
    public function scopeUntilDate(Builder $query, mixed $date, ?string $column = null): Builder
    {
        $column ??= static::dayColumn();

        return $query->where($column, '<', Carbon::parse($date)->startOfDay()->addDay()->toDateString());
    }

    /**
     * firstOrCreate / updateOrCreate for a row keyed on a day.
     *
     * Those two look the row up with an equality on every key they are given,
     * so a date among them misses its own row and the insert that follows hits
     * the unique index. That is not theoretical: it crashed a seeding script,
     * and it was live in the bulk mark-present path until this landed.
     *
     * The lookup goes through onDate; only the create writes a date, and it
     * writes the canonical "Y-m-d".
     *
     * @param  array<string, mixed>  $match   non-date keys identifying the row
     * @param  array<string, mixed>  $values  attributes to set
     */
    public static function forDay(array $match, mixed $date, array $values = [], ?string $column = null): static
    {
        $column ??= static::dayColumn();

        $existing = static::query()->where($match)->onDate($date, $column)->first();

        if ($existing !== null) {
            if ($values !== []) {
                $existing->fill($values)->save();
            }

            return $existing;
        }

        return static::create($match + [$column => static::dayKey($date)] + $values);
    }
}
