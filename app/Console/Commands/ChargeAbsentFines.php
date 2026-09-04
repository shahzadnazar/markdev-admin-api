<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\AbsenceFine;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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

        if ($students->isEmpty()) {
            $this->info('No active students — nothing to charge.');

            return self::SUCCESS;
        }

        $charged = 0;
        $total = 0.0;

        // Oldest first, so a catch-up run reads in the order the months happened.
        for ($back = $catchUp; $back >= 0; $back--) {
            $target = $month->copy()->subMonths($back);
            [$monthCharged, $monthTotal] = $this->chargeMonth($target, $students, $dryRun);
            $charged += $monthCharged;
            $total += $monthTotal;
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing written.');
        } else {
            $this->info(sprintf('%d student-month(s) charged, %s in fines.', $charged, number_format($total, 2)));
        }

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $students
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
