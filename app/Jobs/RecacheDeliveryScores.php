<?php

namespace App\Jobs;

use App\Services\DeliveryScoreCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recompute every cached delivery score.
 *
 * Dispatched when the two delivery settings change. Without it an admin who
 * raises the minimum from three stints to five would see the old percentages
 * on every list until each person next finished something — while that
 * person's own page, which computes live, showed the new answer. Two surfaces
 * disagreeing about the same person is worse than either being briefly stale.
 *
 * Queued or inline is the driver's decision, as RecacheCourseProgress is.
 */
class RecacheDeliveryScores implements ShouldQueue
{
    use Queueable;

    public function handle(DeliveryScoreCache $cache): void
    {
        $cache->refreshAll();
    }
}
