<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduler
|--------------------------------------------------------------------------
| Run with: php artisan schedule:work (or a cron entry for schedule:run).
*/

// THIS IS WHAT MAKES A QUEUED JOB ACTUALLY RUN. Hostinger shared hosting has
// no worker and no supervisor: the only thing running every minute is the cron
// entry behind this scheduler. Without this line QUEUE_CONNECTION=database
// accepts every dispatch, writes it to the jobs table and nothing ever reads
// it -- RecacheCourseProgress and RecacheDeliveryScores would sit there
// forever and the settings save would report success. A dispatch that
// DISAPPEARS is worse than one that fails, so do not remove this without also
// moving the queue to a host that has a worker.
//
// --stop-when-empty is what makes an every-minute worker sane: with an empty
// queue -- the normal case -- it stops instead of idling for the whole minute,
// so it does not hold up anything else due in the same schedule:run. --sleep=0
// is what makes that immediate: the worker's loop sleeps for --sleep BEFORE it
// checks whether it should stop, so the default would burn three seconds of
// every single minute doing nothing. --max-time caps the other case below the
// next tick, so a long backlog is drained across several runs rather than one
// run overrunning into the next. 50 seconds leaves the tick ten to spare.
//
// withoutOverlapping is the first use of it in this file, and it is here
// because the other entries in this file do not need it: each of those is
// idempotent by data -- a settled day, a charged month, a notice with a subject --
// so a second run repeats no work. A worker has no such subject. Two of them
// on the same queue would both be live, and a backlog long enough to hit
// --max-time is exactly when the next tick arrives with the first still
// running. The two-minute expiry matters as much as the flag: the default
// mutex lasts 24 hours, so a worker killed mid-run -- which --max-time and a
// shared host make likely -- would lock the drain out for a day. Two minutes
// is longer than the longest legal run and short enough to forgive a kill.
//
// --stop-when-empty IS PASSED AS A BARE VALUE, not as `=> true`. Schedule
// compiles a keyed parameter to `--name=value`, and that flag takes no value,
// so `['--stop-when-empty' => true]` renders `--stop-when-empty='1'` and the
// command refuses to start: "The --stop-when-empty option does not accept a
// value." It fails silently too -- the scheduler swallows the exit code -- so
// the drain would simply never run. QueueDrainScheduleTest binds this line's
// rendered command against the real queue:work definition to keep it honest.
//
// No --force, deliberately: this project's maintenance mode is a setting read by
// middleware, not `artisan down`, so the worker is never held off by it. Anybody
// who reaches for `artisan down` here stops the drain without a word.
Schedule::command('queue:work', ['--stop-when-empty', '--max-time' => 50, '--sleep' => 0])
    ->everyMinute()
    ->withoutOverlapping(2);
// Settles the daily register: anything still unmarked at 11pm becomes an
// absence. Runs before midnight so the day it closes is the day just ending.
// The catch-up settles the week behind it too, so days the scheduler was not
// running for -- a laptop that was off at 11pm -- are filled in on the next
// run rather than staying open forever. Days already settled cost nothing.
Schedule::command('attendance:close-day', ['--catch-up' => 7])->dailyAt('23:00');
// Totals last month's absences onto the next invoice. Runs on the 1st, after
// the day close has settled the final day of the month it is charging. The
// catch-up settles the two months behind it, so a scheduler that was down does
// not leave a month unbilled; a month already charged costs nothing.
Schedule::command('attendance:charge-absent-fines', ['--catch-up' => 2])->monthlyOn(1, '02:00');
// Tells everyone about a closure the morning before it starts. Deliberately in
// the morning rather than at night: a notice sent at 23:00 on the 30th arrives
// while people are asleep and reads as "tomorrow" to anyone opening it after
// midnight, when the closure has already begun. A closure whose notice was
// missed is picked up by the next morning's run on its own, so no catch-up
// window is passed — the command only needs one to reach a closure that has
// already ended, which is not something to send unasked.
Schedule::command('holidays:announce-upcoming')->dailyAt('07:00');
// The team portal's two deadline notices: a milestone due tomorrow, and a
// project that has gone overdue. In the MORNING for the same reason the holiday
// notice is -- "tomorrow" sent at 23:00 reads as today to anybody opening it
// after midnight. No catch-up window is passed and none is needed: each notice
// carries the id and the date of the thing it is about, so a run that happens
// twice sends nothing the second time. A morning the scheduler was DOWN for is
// not made up: the overdue notice returns on its own, because a late project
// stays late, but the milestone notice has a one-day window and that day has
// gone. Announcing "due tomorrow" about yesterday is worse than silence, and a
// catch-up option would only be a way to do exactly that.
Schedule::command('team:notify-deadlines')->dailyAt('07:15');
Schedule::command('billing:sweep')->dailyAt('00:15');
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily();
Schedule::command('backup:clean')->dailyAt('01:00');
Schedule::command('backup:run')->dailyAt('01:30');
// Fires UnhealthyBackupWasFoundNotification when the newest backup is older
// than config('backup.monitor_backups.*.health_checks') allows. Scheduled
// AFTER backup:run so it judges tonight's attempt rather than last night's.
// Without it monitor_backups is inert config: "your backups have not run for a
// month" is a notice nothing sends, which is the only notice that would tell
// anybody the nightly job had stopped.
Schedule::command('backup:monitor')->dailyAt('01:45');
Schedule::command('queue:prune-batches')->daily();
