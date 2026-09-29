<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * When the office opens, and how late is late.
 *
 * NO SLOTS. The academy judges lateness against a student's attendance slot,
 * falling back to an academy-wide day start; the team portal has no slot
 * concept at all. Two settings and one rule: arriving after office start plus
 * the grace is `late`. No per-person start, no second slot table, and no
 * midnight-crossing question to answer.
 *
 * Stored 24-hour, DISPLAYED 12-hour in Asia/Karachi like every other time in
 * this panel.
 *
 * Settings-backed with constants as the fallback, read through Setting::cached
 * — which loads the whole table once per request and rescues a missing table
 * rather than taking the panel down. Deliberately NOT Cache::remember; that
 * pattern cost this project a 30-second timeout once already.
 *
 * Every key here is team-specific. Changing a student number must never move a
 * team number, and TeamSettingsIsolationTest asserts that in both directions.
 */
class TeamAttendanceConfig
{
    public const START_KEY = 'team_office_start_time';

    public const GRACE_KEY = 'team_late_after_minutes';

    public const DEFAULT_START = '09:00';

    public const DEFAULT_GRACE = 15;

    /** "HH:MM", 24-hour. A nonsense stored value falls back rather than throwing. */
    public static function officeStart(): string
    {
        $value = (string) (Setting::cached(self::START_KEY) ?? self::DEFAULT_START);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : self::DEFAULT_START;
    }

    /** The same time as the panel shows it — 12-hour, which is the house style. */
    public static function officeStartLabel(): string
    {
        return Carbon::createFromFormat('H:i', self::officeStart())->format('g:i A');
    }

    public static function lateAfterMinutes(): int
    {
        $stored = Setting::cached(self::GRACE_KEY);

        if ($stored === null || ! is_numeric($stored)) {
            return self::DEFAULT_GRACE;
        }

        $value = (int) $stored;

        return $value >= 0 && $value <= 240 ? $value : self::DEFAULT_GRACE;
    }

    /** The moment a day tips from present to late. */
    public static function lateThreshold(Carbon $day): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', self::officeStart()));

        return $day->copy()->startOfDay()->setTime($hour, $minute)->addMinutes(self::lateAfterMinutes());
    }

    /**
     * What an arrival at this moment is worth.
     *
     * Inside the grace is present — including the last minute of it, because a
     * grace that excluded its own final minute would be a grace one minute
     * shorter than the number in the form.
     */
    public static function statusForArrival(Carbon $arrivedAt): string
    {
        return $arrivedAt->greaterThan(self::lateThreshold($arrivedAt)) ? 'late' : 'present';
    }
}
