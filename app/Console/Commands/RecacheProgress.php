<?php

namespace App\Console\Commands;

use App\Services\ProgressCache;
use Illuminate\Console\Command;

/**
 * Rebuild every enrolment's cached progress figure.
 *
 * Run once on deploy. Every existing row holds a figure from the old formula —
 * completed lessons over total lessons — which is now only one of four
 * components, so the stored numbers are stale until something recomputes them.
 * Events keep them fresh from then on; this is what catches up the rows that
 * were written before any of this existed.
 *
 * Safe to run at any time and safe to run twice: it recomputes from the raw
 * records, so a second run produces the same answer. It never issues or
 * revokes a certificate.
 */
class RecacheProgress extends Command
{
    protected $signature = 'progress:recache';

    protected $description = 'Rebuild enrollments.progress_percent from the raw component records';

    public function handle(ProgressCache $cache): int
    {
        $this->info('Recomputing every enrolment from its raw records…');

        $done = $cache->refreshAll();

        $this->info("Rebuilt {$done} enrolment".($done === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
