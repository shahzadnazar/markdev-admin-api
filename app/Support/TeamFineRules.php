<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\TeamAbsenceFine;
use App\Models\TeamAttendance;
use App\Models\User;
use App\Notifications\TeamAbsenceFineCharged;
use Illuminate\Support\Carbon;

/**
 * What a month's unexcused absences cost a team member.
 *
 * A LEDGER, NOT BILLING. There is no fee concept in the team portal and no
 * invoices for staff. AbsenceFineCharge was checked before this was written
 * and is coupled to billing — it carries an invoice_id, has an invoice()
 * relation, and AbsenceFine::charge places the row on the student's next bill
 * — so extending it would have pulled the whole billing system in to hold a
 * number somebody reads off a screen. team_absence_fines records what is owed
 * and whether it was settled, and stops there.
 *
 * Its own two settings. Changing the student allowance or the student rate
 * must not move a team figure, and TeamSettingsIsolationTest asserts both
 * directions.
 */
class TeamFineRules
{
    public const ALLOWANCE_KEY = 'team_absent_allowance_per_month';

    public const RATE_KEY = 'team_absent_fine_amount';

    public const DEFAULT_ALLOWANCE = 2;

    public const DEFAULT_RATE = 500.0;

    /** Absences a month forgives before anything is charged. */
    public static function allowance(): int
    {
        $stored = Setting::cached(self::ALLOWANCE_KEY);

        if ($stored === null || ! is_numeric($stored)) {
            return self::DEFAULT_ALLOWANCE;
        }

        $value = (int) $stored;

        return $value >= 1 && $value <= 31 ? $value : self::DEFAULT_ALLOWANCE;
    }

    /**
     * What one chargeable absence costs.
     *
     * Zero is meaningful here, unlike the allowance: it is how an academy says
     * team absences are tracked but never charged for.
     */
    public static function perAbsence(): float
    {
        $stored = Setting::cached(self::RATE_KEY);

        if ($stored === null || ! is_numeric($stored)) {
            return self::DEFAULT_RATE;
        }

        $value = (float) $stored;

        return $value >= 0 && $value <= 100000 ? $value : self::DEFAULT_RATE;
    }

    /** How the month stands, without writing anything. */
    public static function balance(int $userId, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();

        $absences = TeamAttendance::query()
            ->where('user_id', $userId)
            ->betweenDates($start, $start->copy()->endOfMonth())
            ->where('status', 'absent')
            ->count();

        $allowance = self::allowance();
        $chargeable = max(0, $absences - $allowance);
        $rate = self::perAbsence();

        return [
            'month' => $start,
            'allowance' => $allowance,
            'absences' => $absences,
            'chargeable' => $chargeable,
            'rate' => $rate,
            'total' => round($chargeable * $rate, 2),
        ];
    }

    /**
     * Write the month's ledger row, once.
     *
     * A month already recorded is left alone, which is what makes the charge
     * command safe to re-run. A month that came to nothing still gets a row:
     * "settled at zero" and "never looked at" have to be different, or a later
     * correction has no baseline.
     *
     * The row SNAPSHOTS the rules. Raising the rate next quarter does not
     * silently rewrite last quarter's ledger.
     *
     * @return array{fine: TeamAbsenceFine, created: bool}
     */
    public static function charge(int $userId, Carbon $month, bool $dryRun = false): array
    {
        $start = $month->copy()->startOfMonth();

        $existing = TeamAbsenceFine::query()
            ->where('user_id', $userId)
            ->onDate($start, 'month')
            ->first();

        if ($existing !== null) {
            return ['fine' => $existing, 'created' => false];
        }

        $balance = self::balance($userId, $start);

        $fine = new TeamAbsenceFine([
            'user_id' => $userId,
            'month' => TeamAbsenceFine::dayKey($start),
            'allowance' => $balance['allowance'],
            'absences' => $balance['absences'],
            'chargeable' => $balance['chargeable'],
            'rate' => $balance['rate'],
            'total' => $balance['total'],
        ]);

        if (! $dryRun) {
            $fine->save();
            self::announce($fine);
        }

        return ['fine' => $fine, 'created' => true];
    }

    /**
     * Tell the person, once, and only when something is actually owed.
     *
     * HERE rather than in the command, so any future caller notifies too — and
     * because the row is what makes it idempotent. `charge` leaves an existing
     * month alone, so a second run of the month-end job creates nothing and
     * rings nothing; this notification needs no subject of its own the way the
     * daily deadline notices do.
     *
     * A month that came to nothing still gets its row — "settled at zero" and
     * "never looked at" have to be different — but it is not a charge, and a
     * bell for a fine of zero is exactly the noise that makes people stop
     * reading the ones that matter.
     *
     * A dry run writes nothing and therefore says nothing; it is called from
     * the saving branch above for that reason.
     */
    protected static function announce(TeamAbsenceFine $fine): void
    {
        if ($fine->chargeable < 1) {
            return;
        }

        PortalNotifier::notify(User::find($fine->user_id), null, new TeamAbsenceFineCharged($fine));
    }
}
