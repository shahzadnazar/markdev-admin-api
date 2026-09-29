<?php

namespace App\Notifications;

use App\Models\Project;
use App\Notifications\Contracts\CarriesItsSubject;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A project your team is on has passed its due date.
 *
 * The second DAILY notice. Only projects whose schedule is actually running
 * qualify — Project::scopeCountingDays — so a paused project does not ring
 * anybody's bell for days it was told to stop consuming, and a finished one has
 * no schedule left to be late against. That is the same rule daysRemaining()
 * returns null for, read once rather than re-derived.
 *
 * The subject carries the due date as well as the id, so a project whose
 * deadline is pushed out and then missed again is a new fact worth a second
 * notice, while one nobody has touched stays quiet however many times the
 * command runs.
 *
 * How late it is, and nothing about what it is worth.
 */
class ProjectOverdue extends Notification implements CarriesItsSubject
{
    use Queueable;

    public function __construct(public Project $project, public int $daysLate) {}

    public function subject(): string
    {
        return sprintf(
            'project:%d:overdue:%s',
            $this->project->getKey(),
            $this->project->due_date?->toDateString() ?? 'none',
        );
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'A project has gone overdue',
            'message' => sprintf(
                '%s (%s) was due %s — %d day(s) ago.',
                $this->project->name,
                $this->project->code,
                $this->project->due_date?->format('j M Y') ?? 'earlier',
                $this->daysLate,
            ),
            'action_url' => route('admin.projects.show', $this->project, absolute: false),
            'subject' => $this->subject(),
        ];
    }
}
