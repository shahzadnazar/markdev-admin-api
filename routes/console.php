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
Schedule::command('attendance:close-day')->dailyAt('23:00');
Schedule::command('billing:sweep')->dailyAt('00:15');
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily();
Schedule::command('backup:clean')->dailyAt('01:00');
Schedule::command('backup:run')->dailyAt('01:30');
Schedule::command('queue:prune-batches')->daily();
