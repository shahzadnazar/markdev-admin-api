<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\TeamLeaveApplicationDay;
use Illuminate\Support\Carbon;

/**
 * How many days of leave a team member gets each month, and what is left.
 *
 * Its own setting, and its own table. The academy's allowance answers for
 * students; a team member's is a staff matter and the two have no reason to
 * move together.
 *
 * A PENDING day is reserved while it waits, exactly as the academy does it:
 * without that, several requests could be filed at once and only blow the
 * allowance once they were all approved, which is too late to refuse any of
 * them. A declined day releases its reservation.
 */
class TeamLeaveAllowance
{
    public const KEY = 'team_leave_allowance_per_month';

    public const DEFAULT_PER_MONTH = 2;

    /**
     * At least one. Zero would not be an allowance, it would be a ban, and
     * there are clearer ways to say that than a number nobody can meet.
     */
    public static function perMonth(): int
    {
        $stored = Setting::cached(self::KEY);

        if ($stored === null || ! is_numeric($stored)) {
            return self::DEFAULT_PER_MONTH;
        }

        $value = (int) $stored;

        return $value >= 1 && $value <= 31 ? $value : self::DEFAULT_PER_MONTH;
    }

    /** Days already spent or reserved in this month. */
    public static function usedIn(int $userId, Carbon $month): int
    {
        return static::daysQuery($userId, $month)->count();
    }

    /** @return array{allowance: int, used: int, remaining: int, month_label: string} */
    public static function balance(int $userId, Carbon $month): array
    {
        $allowance = self::perMonth();
        $used = self::usedIn($userId, $month);

        return [
            'allowance' => $allowance,
            'used' => $used,
            'remaining' => max(0, $allowance - $used),
            'month_label' => $month->copy()->startOfMonth()->format('F Y'),
        ];
    }

    /**
     * The counted day rows for one person in one month.
     *
     * Ranged, never an equality on the date column: `date` is date-cast, and
     * an equality against it matches on one driver and misses on the other.
     */
    protected static function daysQuery(int $userId, Carbon $month)
    {
        $start = $month->copy()->startOfMonth();

        return TeamLeaveApplicationDay::query()
            ->betweenDates($start, $start->copy()->endOfMonth())
            ->whereIn('status', TeamLeaveApplicationDay::COUNTED)
            ->whereHas('application', fn ($query) => $query->where('user_id', $userId));
    }
}
