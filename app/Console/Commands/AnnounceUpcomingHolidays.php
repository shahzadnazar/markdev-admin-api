<?php

namespace App\Console\Commands;

use App\Support\AcademyCalendar;
use App\Support\HolidayAnnouncer;
use App\Support\HolidayRange;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tells students and instructors a holiday is coming, the day before it starts.
 *
 * Nothing is sent when an admin enters the holiday. Eid typed in January is
 * silent until the morning of the day before it — `holiday_announce_days_before`
 * decides how many days that is — because a notice posted three months early is
 * not a notice anyone will still be looking at.
 *
 * One notice per closure. The table holds one row per date, so a three-day Eid
 * is three rows; HolidayRange groups consecutive dates carrying the same name
 * back into a single closure, and the notice names the whole range. Two
 * different holidays that happen to fall on consecutive days keep their own
 * notices, because their names differ.
 *
 * Idempotent: the notice carries `holiday_id`, so a second run on the same
 * morning finds what it already sent rather than posting again. Every run also
 * brings existing notices back in line with their holidays, so a closure whose
 * dates were moved has its notice rewritten and one that was removed has its
 * notice taken down.
 *
 * A closure whose announce date has already passed — Eid entered on the 30th
 * for the 31st, or on the 31st itself — is announced at once as long as it has
 * not finished. Late notice beats none.
 */
class AnnounceUpcomingHolidays extends Command
{
    protected $signature = 'holidays:announce-upcoming
        {--date= : Treat this as today, defaults to today}
        {--catch-up=0 : Also announce closures that ended this many days ago}
        {--dry-run : Report what would be sent without writing}';

    protected $description = 'Announce holidays starting within the notice period';

    public function handle(HolidayAnnouncer $announcer): int
    {
        $today = ($this->option('date') ? Carbon::parse($this->option('date')) : now())->startOfDay();
        $daysBefore = AcademyCalendar::announceDaysBefore();
        $catchUp = max(0, (int) $this->option('catch-up'));
        $dryRun = (bool) $this->option('dry-run');

        // Read fresh every run, so an admin changing the lead time in Settings
        // has it take effect on the next morning with nothing redeployed.
        $this->line(sprintf('%s: notice period is %d day(s) before a closure starts.', $today->toDateString(), $daysBefore));

        if (! $dryRun) {
            // Before sending anything: a closure that moved or was removed
            // since the last run has its notice corrected first, so a run
            // never leaves a stale notice standing beside a fresh one.
            $this->reconcile($announcer, $today, $daysBefore);
        }

        // Wide enough for anything the notice period can reach, plus the
        // longest range the create form allows either side of it.
        $ranges = HolidayRange::between(
            $today->copy()->subDays($catchUp + 31),
            $today->copy()->addDays($daysBefore + 31),
        );

        $sent = 0;
        $skipped = 0;

        foreach ($ranges as $range) {
            $due = $range->announceOn($daysBefore);

            // Not yet due — Eid in January stays silent.
            if ($due->greaterThan($today)) {
                continue;
            }

            // Over and done with. A notice for a closure that has finished is
            // noise, so it is only sent when a catch-up run asks for it.
            if ($range->end()->lessThan($today->copy()->subDays($catchUp))) {
                continue;
            }

            if ($announcer->announcementFor($range->first()) !== null) {
                $skipped++;

                continue;
            }

            $late = $due->lessThan($today) ? ' (notice was due '.$due->toDateString().')' : '';

            if ($dryRun) {
                $this->line('  would announce: '.$range->headline($today).$late);
                $sent++;

                continue;
            }

            if ($announcer->announce($range, $today) === null) {
                $this->warn('  no active admin to post as — skipped: '.$range->headline($today));

                continue;
            }

            $this->line('  announced: '.$range->headline($today).$late);
            $sent++;
        }

        $this->info($dryRun
            ? "Dry run — {$sent} closure(s) would be announced, {$skipped} already were. Nothing written."
            : "{$sent} closure(s) announced, {$skipped} already were.");

        return self::SUCCESS;
    }

    /**
     * Rewrite or take down notices whose holidays changed since they were sent.
     *
     * The work is the announcer's, so an admin editing a holiday in the panel
     * gets exactly the same reconciliation; this only reports it.
     */
    protected function reconcile(HolidayAnnouncer $announcer, Carbon $today, int $daysBefore): void
    {
        $entries = $announcer->reconcileAround(
            $today->copy()->subMonths(2),
            $today->copy()->addDays($daysBefore)->addMonths(2),
        );

        foreach ($entries as $entry) {
            match ($entry['action']) {
                'withdrew' => $this->line('  withdrew: '.$entry['announcement']->title.' — that holiday is no longer in the calendar.'),
                'updated' => $this->line('  updated: '.$entry['announcement']->title),
                default => null,
            };
        }
    }
}
