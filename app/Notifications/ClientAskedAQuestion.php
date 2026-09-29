<?php

namespace App\Notifications;

use App\Models\ClientQuestion;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * THE EIGHTH EVENT, and it is a decision rather than a drift.
 *
 * Phase 6 fixed the list at seven with a test that fails when the count moves,
 * precisely so an eighth could not arrive unnoticed. This is the eighth, added
 * on purpose: a client asked a question on a project, and the team-lead is the
 * one person who has to know. It passes the same bar the other seven pass —
 * something a person would otherwise miss, and that nothing else on their
 * screens would tell them.
 *
 * To the LEAD, not the team and not every admin. The client asked the lead; a
 * member contributes through the project discussion, and an admin who wants the
 * picture has the project page.
 *
 * ## The client is still told nothing
 *
 * This notification goes one way. A client has no bell, no notifications page
 * and no rows in the notifications table — PortalNotifier refuses them at
 * `inTeamPortal`, which is one answer covering all eight events. A client learns
 * their question was answered by seeing it marked answered on the project.
 */
class ClientAskedAQuestion extends Notification
{
    use Queueable;

    public function __construct(public ClientQuestion $question) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $project = $this->question->project;

        return [
            'title' => 'A client asked a question',
            'message' => sprintf(
                'On %s (%s): "%s"',
                $project?->name ?? 'a project',
                $project?->code ?? '—',
                // Trimmed. The bell is a pointer; the question is read on the
                // project page, where the answer box is.
                Str::limit(trim(preg_replace('/\s+/', ' ', (string) $this->question->body)), 140),
            ),
            'action_url' => $project
                ? route('admin.projects.show', $project, absolute: false)
                : route('admin.projects.index', absolute: false),
        ];
    }
}
