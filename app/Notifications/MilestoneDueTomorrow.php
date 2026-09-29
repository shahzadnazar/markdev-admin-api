<?php

namespace App\Notifications;

use App\Models\ProjectMilestone;
use App\Notifications\Contracts\CarriesItsSubject;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A checkpoint on your team's project falls due tomorrow.
 *
 * One of the two DAILY notices, sent by team:notify-deadlines rather than from
 * a request: nothing happens when the milestone is created, because a notice
 * posted three weeks early is not a notice anybody will still be looking at —
 * the same reasoning AnnounceUpcomingHolidays is built on.
 *
 * The subject carries the milestone id AND its due date, so the command is
 * silent on a second run the same day and speaks again if the date is moved and
 * comes round afresh.
 *
 * No client, no contract value: the project is its name and its code.
 */
class MilestoneDueTomorrow extends Notification implements CarriesItsSubject
{
    use Queueable;

    public function __construct(public ProjectMilestone $milestone) {}

    public function subject(): string
    {
        return sprintf(
            'milestone:%d:due:%s',
            $this->milestone->getKey(),
            $this->milestone->due_date?->toDateString() ?? 'none',
        );
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $project = $this->milestone->project;

        return [
            'title' => 'A milestone is due tomorrow',
            'message' => sprintf(
                '"%s" on %s (%s) is due %s.',
                $this->milestone->name,
                $project?->name ?? 'a project',
                $project?->code ?? '—',
                $this->milestone->due_date?->format('j M Y') ?? 'soon',
            ),
            'action_url' => $project ? route('admin.projects.show', $project, absolute: false) : null,
            // Stored, because PortalNotifier reads the ROW and not this object
            // when it decides whether the bell has already rung for this fact.
            'subject' => $this->subject(),
        ];
    }
}
