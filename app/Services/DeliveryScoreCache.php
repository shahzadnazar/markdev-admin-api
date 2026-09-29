<?php

namespace App\Services;

use App\Models\DeliveryScoreRecord;
use App\Models\TaskAssignment;
use App\Models\User;

/**
 * Keeps delivery_scores in step with the stints.
 *
 * ON THE EVENT, NOT ON A SCHEDULE — the precedent ProgressCache set. There is
 * no nightly job. The four things that can move a figure each refresh it:
 *
 *   a stint closes            TaskAssignmentController / the reassignment
 *   a task's status changes   TaskController::move — a blocked day changes the
 *                             days taken of whoever holds it
 *   a reassignment            closes one stint and opens another
 *   the settings save         SettingController::update, via RecacheDeliveryScores
 *
 * `delivery:recache` catches up rows written before any of this existed.
 */
class DeliveryScoreCache
{
    public function __construct(protected DeliveryScoreCalculator $calculator) {}

    public function refresh(User $user): DeliveryScoreRecord
    {
        $score = $this->calculator->for($user);

        return DeliveryScoreRecord::updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'percent' => $score['percent'],
                'stints_completed' => $score['stints_completed'],
                'early_count' => $score['early_count'],
                'late_count' => $score['late_count'],
                'days_over' => $score['days_over'],
                'blocked_days' => $score['blocked_days'],
                'computed_at' => now(),
            ],
        );
    }

    /** Everyone who has ever held a stint on this task. */
    public function refreshForTask(int $taskId): void
    {
        User::query()
            ->whereIn('id', TaskAssignment::where('task_id', $taskId)->select('user_id'))
            ->get()
            ->each(fn (User $user) => $this->refresh($user));
    }

    /** Everyone with a stint at all. Returns how many rows were written. */
    public function refreshAll(): int
    {
        $done = 0;

        User::query()
            ->whereIn('id', TaskAssignment::query()->select('user_id'))
            ->chunkById(200, function ($users) use (&$done) {
                foreach ($users as $user) {
                    $this->refresh($user);
                    $done++;
                }
            });

        return $done;
    }
}
