<?php

namespace App\Jobs;

use App\Services\ProgressCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recompute every enrolment's cached progress figure.
 *
 * Dispatched when the weights change in Settings. Without it an admin who
 * moves attendance from 40 to 30 would see the old numbers on every list until
 * each student happened to do something — while the student's own Progress
 * page, which computes live, showed the new one. Two surfaces disagreeing
 * about the same student is worse than either being briefly stale.
 *
 * INLINE OR QUEUED IS THE DRIVER'S DECISION, not this class's. It implements
 * ShouldQueue and is dispatched normally: on the sync driver this project ships
 * with it runs inside the settings save, and on a real queue driver it goes to
 * a worker. That is deliberate — an academy with a hundred enrolments wants the
 * numbers right when the page reloads, and one with fifty thousand does not
 * want the save to block. ProgressCache::refreshAll chunks either way.
 */
class RecacheCourseProgress implements ShouldQueue
{
    use Queueable;

    public function handle(ProgressCache $cache): void
    {
        $cache->refreshAll();
    }
}
