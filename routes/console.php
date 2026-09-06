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
Schedule::command('billing:sweep')->dailyAt('00:15');
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily();
Schedule::command('backup:clean')->dailyAt('01:00');
Schedule::command('backup:run')->dailyAt('01:30');
Schedule::command('queue:prune-batches')->daily();
