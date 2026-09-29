<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\AbsenceFine;
use App\Support\TeamFineRules;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Totals a month's absences and puts the fine on the student's next invoice.
 *
 * Charged once at month end rather than as each absence lands: a student who
 * is absent on the 3rd may have it corrected on the 4th, and billing them in
 * between only creates work to undo.
 *
 * Safe to re-run. A month is charged once per student and the row saying so is
 * what stops a second run doing it again — including a month that came to
 * nothing, because "settled at zero" and "never looked at" have to be
 * different or a later correction has no baseline.
 */
class ChargeAbsentFines extends Command
{
    protected $signature = 'attendance:charge-absent-fines
        {--month= : Month to charge, defaults to the one just ended}
        {--catch-up=0 : Also settle this many earlier months, for runs the scheduler missed}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Bill each student for absences beyond their monthly allowance';

    public function handle(): int
    {
        $month = $this->option('month')
            ? Carbon::parse($this->option('month'))->startOfMonth()
            // The month just ended: run on the 1st and it settles the month
            // that closed the night before.
            : now()->startOfMonth()->subMonth();

        $catchUp = max(0, (int) $this->option('catch-up'));
        $dryRun = (bool) $this->option('dry-run');

        $students = User::role('student')->where('is_active', true)->pluck('id');

        // Staff, by role. Their fines are a LEDGER rather than a bill — see
        // TeamFineRules for why AbsenceFineCharge was not extended — but they
        // are settled by the same command on the same schedule, because two
        // month-end jobs is one more thing to forget to deploy.
        $members = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['team-lead', 'team']))
            ->where('is_active', true)
            ->pluck('id');

        if ($students->isEmpty() && $members->isEmpty()) {
            $this->info('Nobody active to charge.');

            return self::SUCCESS;
        }

        $charged = 0;
        $total = 0.0;
        $teamCharged = 0;
        $teamTotal = 0.0;

        // Oldest first, so a catch-up run reads in the order the months happened.
        for ($back = $catchUp; $back >= 0; $back--) {
            $target = $month->copy()->subMonths($back);

            if ($students->isNotEmpty()) {
                [$monthCharged, $monthTotal] = $this->chargeMonth($target, $students, $dryRun);
                $charged += $monthCharged;
                $total += $monthTotal;
            }

            if ($members->isNotEmpty()) {
                [$teamMonthCharged, $teamMonthTotal] = $this->chargeTeamMonth($target, $members, $dryRun);
                $teamCharged += $teamMonthCharged;
                $teamTotal += $teamMonthTotal;
            }
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing written.');
        } else {
            $this->info(sprintf('%d student-month(s) charged, %s in fines.', $charged, number_format($total, 2)));
            $this->info(sprintf('%d team-month(s) recorded, %s owed.', $teamCharged, number_format($teamTotal, 2)));
        }

        return self::SUCCESS;
    }

    /**
     * Record a month of the team ledger.
     *
     * Its own settings throughout — the team allowance and the team rate — so
     * moving a student number never moves a staff one. A month already
     * recorded is left alone, which is what makes the command safe to re-run.
     *
     * @param  Collection<int, int>  $members
     * @return array{0: int, 1: float}
     */
    protected function chargeTeamMonth(Carbon $month, $members, bool $dryRun): array
    {
        $recorded = 0;
        $skipped = 0;
        $total = 0.0;

        foreach ($members as $userId) {
            $result = TeamFineRules::charge($userId, $month, $dryRun);

            if (! $result['created']) {
                $skipped++;

                continue;
            }

            $recorded++;
            $total += (float) $result['fine']->total;
        }

        $this->line(sprintf(
            '%s (team): %d recorded, %d already settled, %s owed',
            $month->format('F Y'),
            $recorded,
            $skipped,
            number_format($total, 2),
        ));

        return [$recorded, $total];
    }

    /**
     * @param  Collection<int, int>  $students
     * @return array{0: int, 1: float}
     */
    protected function chargeMonth(Carbon $month, $students, bool $dryRun): array
    {
        $label = $month->format('F Y');
        $charged = 0;
        $skipped = 0;
        $total = 0.0;

        foreach ($students as $userId) {
            if (! $dryRun) {
                // Credits from corrections made before this student had an
                // invoice to carry them get placed now that one may exist.
                AbsenceFine::placePendingCredits($userId);
            }

            $result = AbsenceFine::charge($userId, $month, $dryRun);

            if (! $result['created']) {
                $skipped++;

                continue;
            }

            $charged++;
            $total += (float) $result['charge']->amount;
        }

        $this->line(sprintf(
            '%s: %d charged, %d already settled, %s total',
            $label,
            $charged,
            $skipped,
            number_format($total, 2),
        ));

        return [$charged, $total];
    }
}
