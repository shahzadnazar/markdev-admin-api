<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A task you were holding is now somebody else's.
 *
 * Its own event, and not a variation of TaskAssigned, because the person it
 * LEFT is the one most likely to miss it: nothing on their own screens changes
 * except that the work quietly stops being there. Their stint is closed as
 * `handed_over` and sits in neither half of their score, which is worth saying
 * plainly — somebody who thinks they were marked late for a handover will
 * otherwise argue about a number that was never counted.
 */
class TaskReassignedAway extends Notification
{
    use Queueable;

    public function __construct(
        public Task $task,
        public ?User $to = null,
        public ?User $by = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'A task moved off your list',
            'message' => sprintf(
                '%s moved "%s" to %s. Your stint is closed as handed over — it counts neither on time nor late.',
                $this->by?->name ?? 'A lead',
                $this->task->title,
                $this->to?->name ?? 'somebody else',
            ),
            'action_url' => route('admin.tasks.show', $this->task, absolute: false),
        ];
    }
}
