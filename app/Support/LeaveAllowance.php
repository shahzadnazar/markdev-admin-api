<?php

namespace App\Support;

use App\Models\LeaveApplicationDay;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How much leave a student may take in a calendar month.
 *
 * The allowance is a whole number of days per month, set by an admin, and it
 * does not accumulate: an unused February day is gone on 1 March. Each month
 * is therefore counted on its own, and a request spanning two months has to
 * fit in both.
 *
 * Months are Asia/Karachi calendar months, which is what Carbon gives by
 * default here — config/app.php fixes the application to that zone.
 */
class LeaveAllowance
{
    /**
     * Days of leave allowed per calendar month.
     *
     * The fallback is only what a database with no saved setting reports; the
     * admin Settings page writes the real value and everything reads it from
     * there.
     */
    public static function perMonth(): int
    {
        return max(1, (int) (Setting::cached('monthly_leave_allowance') ?? 2));
    }

    /**
     * Days already spent in this month — approved, plus pending days still
     * holding their reservation.
     */
    public static function usedIn(int $userId, Carbon $month): int
    {
        return static::daysQuery($userId, $month)->count();
    }

    /**
     * @return array{allowance: int, used: int, remaining: int, month: string, month_label: string, resets_on: string}
     */
    public static function balance(int $userId, Carbon $month): array
    {
        $allowance = static::perMonth();
        $used = static::usedIn($userId, $month);
        $start = $month->copy()->startOfMonth();

        return [
            'allowance' => $allowance,
            'used' => $used,
            'remaining' => max(0, $allowance - $used),
            'month' => $start->format('Y-m'),
            'month_label' => $start->format('F Y'),
            // When this month's balance is replaced by a fresh one. Nothing
            // rolls over into it.
            'resets_on' => $start->copy()->addMonth()->toDateString(),
        ];
    }

    /**
     * Balances for the months a student can currently apply into.
     *
     * The apply form reaches 60 days ahead, so a range can land in this month
     * or either of the next two; the portal is given all three rather than
     * calculating any of it.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function upcomingBalances(int $userId, ?Carbon $from = null): array
    {
        $start = ($from ?? Carbon::now())->copy()->startOfMonth();

        return collect(range(0, 2))
            ->map(fn (int $ahead) => static::balance($userId, $start->copy()->addMonths($ahead)))
            ->all();
    }

    /**
     * How many days of a range this student actually spends, per month.
     *
     * Only the days the academy expects them on: a Friday-to-Monday request
     * over a closed weekend costs two days, not four, and a range sitting
     * entirely on a holiday costs nothing. This has to agree with
     * LeaveApplication::days(), which is what gets written — both ask
     * AcademyCalendar, so they cannot drift apart.
     *
     * @return Collection<string, int> "Y-m" => number of days
     */
    public static function daysPerMonth(User $student, Carbon $from, Carbon $to): Collection
    {
        return AcademyCalendar::expectedDatesFor($student, $from, $to)
            ->countBy(fn (Carbon $day) => $day->format('Y-m'));
    }

    /**
     * The first month a range would overspend, or null if it fits everywhere.
     *
     * Each month is checked against its own remaining balance, so a range
     * straddling a boundary is only refused for the month that is short — and
     * the message can say which.
     *
     * @return array{month: string, month_label: string, remaining: int, needed: int}|null
     */
    public static function shortfall(int $userId, Carbon $from, Carbon $to): ?array
    {
        $student = User::with('studentProfile.attendanceSlot')->find($userId);

        if ($student === null) {
            return null;
        }

        foreach (static::daysPerMonth($student, $from, $to) as $month => $needed) {
            $balance = static::balance($userId, Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth());

            if ($needed > $balance['remaining']) {
                return [
                    'month' => $balance['month'],
                    'month_label' => $balance['month_label'],
                    'remaining' => $balance['remaining'],
                    'needed' => $needed,
                ];
            }
        }

        return null;
    }

    /** The sentence a student is shown when a range does not fit. */
    public static function shortfallMessage(array $shortfall): string
    {
        $monthName = Carbon::createFromFormat('Y-m-d', $shortfall['month'].'-01')->format('F');

        return $shortfall['remaining'] === 0
            ? "No leave remaining in {$monthName}."
            : "Only {$shortfall['remaining']} leave remaining in {$monthName}.";
    }

    /** @return \Illuminate\Database\Eloquent\Builder<LeaveApplicationDay> */
    protected static function daysQuery(int $userId, Carbon $month)
    {
        $start = $month->copy()->startOfMonth();

        return LeaveApplicationDay::query()
            ->whereIn('status', LeaveApplicationDay::COUNTED)
            // Half-open: `date` is a date-cast column, so on SQLite it comes
            // back as a full datetime and a <= against the month's last day
            // would drop that day.
            ->where('date', '>=', $start->toDateString())
            ->where('date', '<', $start->copy()->addMonth()->toDateString())
            ->whereHas('leaveApplication', fn ($query) => $query->where('user_id', $userId));
    }
}
