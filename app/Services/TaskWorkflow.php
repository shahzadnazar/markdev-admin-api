<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskStatus;
use App\Models\TaskStatusPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything that happens to a task when it moves or changes hands.
 *
 * One place, because these rules come in pairs that must not drift: a status
 * change closes one period and opens another, a handover closes one stint and
 * opens another, and both of them move somebody's score. A controller doing
 * half of a pair is how a figure goes quietly wrong.
 */
class TaskWorkflow
{
    public function __construct(
        protected StintClock $clock,
        protected DeliveryScoreCache $scores,
    ) {}

    /**
     * Record the status a task starts life on.
     *
     * Every task gets a period from the moment it exists, so the day counter
     * never has to guess what it was doing before its first move.
     */
    public function openStatusPeriod(Task $task, ?User $by = null, ?string $reason = null): TaskStatusPeriod
    {
        return TaskStatusPeriod::create([
            'task_id' => $task->getKey(),
            'task_status_id' => $task->task_status_id,
            'started_on' => TaskStatusPeriod::dayKey(today()),
            'reason' => $reason,
            'changed_by' => $by?->getKey(),
        ]);
    }

    /**
     * Move a task to another status.
     *
     * A BLOCKED status needs a written reason. Parking a task in Blocked stops
     * the holder's clock, so it is a decision somebody has to justify — the
     * same shape as declining a leave application. A member may do it: they
     * are the first to know they are stuck, and the answer to the obvious
     * worry is that every blocked day is counted and shown, not that parking
     * is forbidden.
     *
     * Moving ONTO a `done` status closes the open stint with the outcome the
     * clock computes. Moving OFF one opens a fresh stint for whoever held the
     * last, carrying the same allowance — they were given N days to do it and
     * are being given N days to put it right. The finished stint is untouched:
     * its ended_on and its outcome are what happened, and a reopen does not
     * unhappen them. That history IS the lock; there is no absent-style edit
     * window here because nothing here overwrites.
     */
    public function changeStatus(Task $task, TaskStatus $to, ?User $by, ?string $reason = null): Task
    {
        if ($to->behaviour === 'blocked' && trim((string) $reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Say why this is blocked. Parking a task stops its clock, so the reason is recorded against it.',
            ]);
        }

        if (! $to->is_active) {
            throw ValidationException::withMessages([
                'task_status_id' => 'That status is retired and cannot be given to a task.',
            ]);
        }

        return DB::transaction(function () use ($task, $to, $by, $reason) {
            $wasDone = $task->isDone();
            $from = (int) $task->task_status_id;

            if ($from !== (int) $to->getKey()) {
                TaskStatusPeriod::query()->where('task_id', $task->getKey())->open()->get()
                    ->each(fn (TaskStatusPeriod $period) => $period->closeOn(today()));
            }

            $task->task_status_id = $to->getKey();
            $task->save();
            $task->setRelation('status', $to);

            if ($from !== (int) $to->getKey()) {
                $this->openStatusPeriod($task, $by, $to->behaviour === 'blocked' ? $reason : null);
            }

            $nowDone = $to->behaviour === 'done';

            if ($nowDone && ! $wasDone) {
                $this->closeOpenStint($task);
            }

            if ($wasDone && ! $nowDone) {
                $this->reopen($task, $by);
            }

            $this->scores->refreshForTask($task->getKey());

            return $task;
        });
    }

    /**
     * Hand a task to someone, with a stated allowance.
     *
     * The open stint closes as `handed_over` — neither on time nor late, and
     * in neither half of the score, because the leaver did not finish and was
     * not given the chance to be late. Its days stay visible on the task.
     *
     * The remaining days are NEVER carried over. Somebody types a new number
     * and is accountable for it; a stint opened with whatever was left would
     * doom whoever picked it up for a delay that was not theirs, and a stint
     * opened with nothing left would doom them on day one.
     */
    public function assign(Task $task, User $to, int $daysAllowed, ?User $by): TaskAssignment
    {
        return DB::transaction(function () use ($task, $to, $daysAllowed, $by) {
            $previous = $task->assignments()->whereNull('ended_on')->first();

            if ($previous !== null) {
                $previous->update([
                    'ended_on' => TaskAssignment::dayKey(today()),
                    'outcome' => 'handed_over',
                ]);
            }

            $stint = TaskAssignment::create([
                'task_id' => $task->getKey(),
                'user_id' => $to->getKey(),
                'days_allowed' => $daysAllowed,
                'started_on' => TaskAssignment::dayKey(today()),
                'created_by' => $by?->getKey(),
            ]);

            $this->scores->refreshForTask($task->getKey());

            return $stint;
        });
    }

    /**
     * Close the stint currently holding this task, judging it on ITS OWN
     * allowance — never the parent's.
     */
    public function closeOpenStint(Task $task): ?TaskAssignment
    {
        $stint = $task->assignments()->whereNull('ended_on')->first();

        if ($stint === null) {
            return null;
        }

        $stint->ended_on = TaskAssignment::dayKey(today());
        $stint->outcome = $this->clock->outcomeFor($stint);
        $stint->save();

        return $stint;
    }

    /**
     * Reopen: a NEW stint beside the finished one, not an edit to it.
     *
     * Same person, same allowance. Nothing about the first stint changes —
     * a task delivered late, reopened and fixed still reads as one late stint
     * and one more stint, which is what happened.
     */
    protected function reopen(Task $task, ?User $by): ?TaskAssignment
    {
        $last = $task->assignments()->orderByDesc('id')->first();

        if ($last === null) {
            return null;
        }

        return TaskAssignment::create([
            'task_id' => $task->getKey(),
            'user_id' => $last->user_id,
            'days_allowed' => $last->days_allowed,
            'started_on' => TaskAssignment::dayKey(today()),
            'created_by' => $by?->getKey(),
        ]);
    }
}
