<?php

namespace App\Console\Commands;

use App\Support\TeamDeadlineNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Rings the bell for a deadline arriving: one command, both notices.
 *
 * ONE command for the milestone notice and the overdue notice, not two. They
 * run on the same morning, read the same two tables and are told to the same
 * people; two scheduled jobs would be one more thing to forget to deploy —
 * the same reasoning that put the team ledger inside
 * attendance:charge-absent-fines rather than beside it.
 *
 * Idempotent, and not by a timestamp: every notice carries the id AND the date
 * of the thing it is about, so a second run this morning finds what it already
 * sent. See TeamDeadlineNotifier and App\Notifications\Contracts\CarriesItsSubject.
 *
 * Nothing here is email. The portal bell is the whole delivery mechanism in this
 * phase — no mail driver, no queue, no per-person preference.
 */
class NotifyTeamDeadlines extends Command
{
    protected $signature = 'team:notify-deadlines
        {--date= : Treat this as today, defaults to today}
        {--dry-run : Report what would be sent without writing}';

    protected $description = 'Notify teams of milestones due tomorrow and projects gone overdue';

    public function handle(TeamDeadlineNotifier $notifier): int
    {
        $today = ($this->option('date') ? Carbon::parse($this->option('date')) : now())->startOfDay();
        $dryRun = (bool) $this->option('dry-run');

        $sent = $notifier->run($today, $dryRun);

        $this->line(sprintf('%s: %d milestone notice(s) due tomorrow, %d overdue project notice(s).',
            $today->toDateString(),
            $sent['milestones'],
            $sent['overdue'],
        ));

        // Not a warning. A morning on which everything has already been said is
        // the normal case for a command that runs every day.
        $this->line(sprintf('  %d skipped — already sent, or not the recipient\'s work to know about.', $sent['skipped']));

        if ($dryRun) {
            $this->comment('Dry run — nothing written.');
        }

        return self::SUCCESS;
    }
}
