<?php

namespace App\Services;

use App\Models\TaskAssignment;
use App\Models\User;
use App\Support\DeliveryScore;

/**
 * One person's delivery score, computed live from their stints.
 *
 * ## Day-weighted, never task-counted
 *
 *   score = allowed days of on-time-or-better stints
 *           ÷ allowed days of all FINISHED stints
 *
 * Weighted by days because five one-day tasks delivered on time must not
 * outrank one ten-day task delivered a day late. Counting stints would make
 * the smallest work the most valuable thing a person could pick up.
 *
 * `handed_over` is in NEITHER half. The leaver did not finish, so they cannot
 * be marked on time, and they were not given the chance to be late either.
 *
 * ## Never a bare number
 *
 * This returns the counts alongside the percentage — stints completed, how
 * many were late, days over, blocked days — because the component that renders
 * it refuses to draw a percentage without them, and a caller that had to fetch
 * the context separately would eventually not bother.
 *
 * Below the minimum, `percent` is null. Null is "not enough completed work to
 * say", which is a different statement from 0 and stays different all the way
 * to the screen.
 */
class DeliveryScoreCalculator
{
    public function __construct(protected StintClock $clock) {}

    /**
     * @return array{percent: ?int, stints_completed: int, late_count: int, days_over: int, blocked_days: int, minimum: int}
     */
    public function for(User $user): array
    {
        $stints = TaskAssignment::query()
            ->forUser($user->getKey())
            ->with(['task.project', 'task.status'])
            ->get();

        // Three queries for every stint this person has ever held, rather than
        // three per stint. A query-count test is what caught the difference.
        $this->clock->primeFor($stints);

        $finished = $stints->whereIn('outcome', TaskAssignment::FINISHED);

        $numerator = 0.0;
        $denominator = 0;
        $late = 0;
        $daysOver = 0;

        foreach ($finished as $stint) {
            $allowed = (int) $stint->days_allowed;
            $denominator += $allowed;

            if (in_array($stint->outcome, TaskAssignment::KEPT_THE_PROMISE, true)) {
                $numerator += $allowed * ($stint->outcome === 'early' ? DeliveryScore::earlyWeight() : 1.0);

                continue;
            }

            $late++;
            $daysOver += $this->clock->daysOver($stint);
        }

        // Blocked days across EVERY stint, open ones included: parking is
        // visible whether or not the task is finished.
        $blocked = $stints->sum(fn (TaskAssignment $stint) => $this->clock->blockedDays($stint));

        $completed = $finished->count();
        $minimum = DeliveryScore::minimumStints();

        return [
            'percent' => $completed >= $minimum && $denominator > 0
                // Capped: the early bonus lifts a mixed record, it does not
                // invent a score above full marks.
                ? (int) min(100, round($numerator / $denominator * 100))
                : null,
            'stints_completed' => $completed,
            'late_count' => $late,
            'days_over' => $daysOver,
            'blocked_days' => (int) $blocked,
            'minimum' => $minimum,
        ];
    }
}
