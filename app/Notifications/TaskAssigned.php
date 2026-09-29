<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A task is now yours, for a stated number of days.
 *
 * The allowance is in the message because it is the thing being promised on
 * your behalf, and phase 3's delivery score judges you against it. Somebody
 * who is not told the number cannot object to it.
 *
 * No client, no contract value: the project is named by its name and its code,
 * which is all a team person is ever shown of it.
 */
class TaskAssigned extends Notification
{
    use Queueable;

    public function __construct(
        public Task $task,
        public int $daysAllowed,
        public ?User $by = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $project = $this->task->project;

        return [
            'title' => 'A task was assigned to you',
            'message' => sprintf(
                '%s gave you "%s"%s — %d day(s).',
                $this->by?->name ?? 'A lead',
                $this->task->title,
                $project ? sprintf(' on %s (%s)', $project->name, $project->code) : '',
                $this->daysAllowed,
            ),
            'action_url' => route('admin.tasks.show', $this->task, absolute: false),
        ];
    }
}
