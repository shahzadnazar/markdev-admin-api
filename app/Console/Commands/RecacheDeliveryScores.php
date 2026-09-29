<?php

namespace App\Console\Commands;

use App\Services\DeliveryScoreCache;
use Illuminate\Console\Command;

/**
 * Rebuild every cached delivery score from the raw stints.
 *
 * Run once on deploy: everyone who held a stint before this phase existed has
 * no cached row at all, so every list screen would read "no score" for them
 * until they next finished something. Events keep the rows fresh from then on.
 *
 * Safe to run at any time and safe to run twice — it recomputes from the stints
 * themselves, so a second run produces the same answer.
 */
class RecacheDeliveryScores extends Command
{
    protected $signature = 'delivery:recache';

    protected $description = 'Rebuild delivery_scores from the raw task stints';

    public function handle(DeliveryScoreCache $cache): int
    {
        $this->info('Recomputing every delivery score from its stints…');

        $done = $cache->refreshAll();

        $this->info("Rebuilt {$done} score".($done === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
