<?php

namespace App\Support;

use App\Models\AttendanceSlot;
use App\Models\Holiday;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which dates the academy expects a student on.
 *
 * Three rules, and every part of the system that asks "should this day count?"
 * asks here rather than keeping its own copy:
 *
 *   a) a student on a slot follows that slot's weekday list
 *   b) a student on no slot follows `academy_working_days`
 *   c) a holiday closes the academy for everybody, slot or not
 *
 * (c) is unconditional and beats (a): a slot that runs Saturdays says nothing
 * about a Saturday the academy is shut. A day nobody is expected on cannot
 * produce an absence, and since 459f3cc an absence is billable — so this class
 * is on the path to a charge, and the same answer has to reach the register,
 * the leave allowance and the fine.
 *
 * All dates are Asia/Karachi, which is what Carbon gives here.
 */
class AcademyCalendar
{
    /**
     * Weekdays the academy is open when nothing else says otherwise.
     *
     * ISO-8601 numbers, 1 = Monday, matching Carbon::dayOfWeekIso and the
     * `days` column on slots so the two lists are directly comparable.
     */
    public const DEFAULT_WORKING_DAYS = [1, 2, 3, 4, 5];

    /**
     * The academy's open weekdays, cleaned up and in week order.
     *
     * Never empty: an academy that never opens would mark nobody and bill
     * nobody, so a missing or nonsense setting falls back to Mon–Fri rather
     * than silently switching attendance off. The Settings form refuses an
     * empty selection, which is where that is a visible error rather than a
     * quiet default.
     *
     * @return array<int, int>
     */
    public static function workingDays(): array
    {
        $days = array_values(array_unique(array_filter(
            array_map('intval', (array) (Setting::cached('academy_working_days') ?? [])),
            fn (int $day) => isset(AttendanceSlot::DAYS[$day]),
        )));

        sort($days);

        return $days === [] ? self::DEFAULT_WORKING_DAYS : $days;
    }

    /** Whether the academy is open on this weekday, holidays aside. */
    public static function isWorkingWeekday(Carbon $day): bool
    {
        return in_array($day->dayOfWeekIso, static::workingDays(), true);
    }

    /** e.g. "Mon–Fri" — the same phrasing slots use for their own days. */
    public static function workingDaysLabel(): string
    {
        return AttendanceSlot::labelForDays(static::workingDays());
    }

    /**
     * How many days before a holiday starts its notice goes out.
     *
     * 1 means the morning before. Never zero: a notice that lands on the day
     * itself is not notice, and the announcer treats a holiday added too late
     * for its lead time as a special case rather than making this the way to
     * express it.
     */
    public static function announceDaysBefore(): int
    {
        return max(1, min(30, (int) (Setting::cached('holiday_announce_days_before') ?? 1)));
    }

    /* ------------------------------- Holidays ------------------------------ */

    /**
     * Holiday names for a date range, keyed by Y-m-d.
     *
     * Keyed in PHP on the formatted date rather than compared in SQL: `date`
     * is a date-cast column and an equality against it finds nothing on
     * SQLite. Callers settling a range of days fetch this once and look each
     * day up, which also keeps the close to one query however many days it
     * catches up on.
     *
     * @return Collection<string, string>
     */
    public static function holidayMap(mixed $from, mixed $to): Collection
    {
        return Holiday::betweenDates($from, $to)
            ->orderBy('date')
            ->get(['date', 'name'])
            ->mapWithKeys(fn (Holiday $holiday) => [$holiday->date->toDateString() => $holiday->name]);
    }

    /** The holiday on this date, or null. */
    public static function holidayName(mixed $date): ?string
    {
        return static::holidayMap($date, $date)->first();
    }

    public static function isHoliday(mixed $date): bool
    {
        return static::holidayName($date) !== null;
    }

    /**
     * How many working days a date range contains, both ends included.
     *
     * THE ONE COUNTER. Weekends the academy is shut and holidays are dropped;
     * everything that needs "how many working days between these two dates"
     * asks here rather than writing its own loop — the team portal's delivery
     * score, its stint clocks and anything after them.
     *
     * `$exclude` is for days a caller knows should not count for a reason this
     * class cannot see: a task parked on a blocked status, a project paused.
     * Passed as Y-m-d strings so overlapping reasons collapse into one set and
     * a day that is both blocked and paused is subtracted once, not twice.
     *
     * Pass `$holidays` when counting several ranges to avoid a query each; it
     * is the same Y-m-d => name map holidayMap returns.
     *
     * @param  Collection<string, string>|null  $holidays  Y-m-d => name
     * @param  array<int, string>|Collection<int, string>  $exclude  Y-m-d strings
     */
    public static function workingDaysBetween(mixed $from, mixed $to, ?Collection $holidays = null, array|Collection $exclude = []): int
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        if ($end->lessThan($start)) {
            return 0;
        }

        $holidays ??= static::holidayMap($start, $end);
        $skip = array_flip(collect($exclude)->all());
        $count = 0;

        for ($day = $start->copy(); $day->lessThanOrEqualTo($end); $day->addDay()) {
            $key = $day->toDateString();

            if (isset($skip[$key]) || $holidays->has($key) || ! static::isWorkingWeekday($day)) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * The working dates in a range, as Y-m-d strings.
     *
     * Same rules as workingDaysBetween; returned rather than counted for
     * callers that need to intersect them with something else.
     *
     * @param  Collection<string, string>|null  $holidays  Y-m-d => name
     * @return array<int, string>
     */
    public static function workingDatesBetween(mixed $from, mixed $to, ?Collection $holidays = null): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        if ($end->lessThan($start)) {
            return [];
        }

        $holidays ??= static::holidayMap($start, $end);
        $dates = [];

        for ($day = $start->copy(); $day->lessThanOrEqualTo($end); $day->addDay()) {
            $key = $day->toDateString();

            if ($holidays->has($key) || ! static::isWorkingWeekday($day)) {
                continue;
            }

            $dates[] = $key;
        }

        return $dates;
    }

    /* ------------------------------ The verdict ---------------------------- */

    /**
     * Whether a student on this slot is expected on this date.
     *
     * Takes the slot rather than the student so the close can pass one it has
     * already eager-loaded; `expectsStudent` is the same question asked of a
     * User. Pass `$holidays` when settling several days, to avoid a query per
     * day.
     *
     * @param  Collection<string, string>|null  $holidays  Y-m-d => name
     */
    public static function expects(?AttendanceSlot $slot, Carbon $day, ?Collection $holidays = null): bool
    {
        $holiday = $holidays !== null
            ? $holidays->get($day->toDateString())
            : static::holidayName($day);

        if ($holiday !== null) {
            return false;
        }

        // A slot is a statement about which days its students attend, so it
        // answers for them; only a student without one falls back to the
        // academy-wide week.
        return $slot !== null ? $slot->runsOn($day) : static::isWorkingWeekday($day);
    }

    /** @param  Collection<string, string>|null  $holidays  Y-m-d => name */
    public static function expectsStudent(User $student, Carbon $day, ?Collection $holidays = null): bool
    {
        return static::expects($student->studentProfile?->attendanceSlot, $day, $holidays);
    }

    /**
     * The dates in a range this student is expected on.
     *
     * What the leave allowance spends and what a fine can be charged for are
     * the same set of days, so both count with this.
     *
     * @return Collection<int, Carbon>
     */
    public static function expectedDatesFor(User $student, Carbon $from, Carbon $to): Collection
    {
        $holidays = static::holidayMap($from, $to);
        $slot = $student->studentProfile?->attendanceSlot;
        $days = collect();

        for ($day = $from->copy()->startOfDay(); $day->lessThanOrEqualTo($to->copy()->startOfDay()); $day->addDay()) {
            if (static::expects($slot, $day, $holidays)) {
                $days->push($day->copy());
            }
        }

        return $days;
    }
}
