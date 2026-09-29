<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Somebody wrote your handle in a staff conversation.
 *
 * Sent from the STORED mention rows, not from the comment text — phase 5 wrote
 * those rows precisely so this phase would have a fact to read rather than a
 * string to re-parse against a membership list that may have changed since.
 *
 * The comment says where it lives; this class does not match on its own class
 * name. Three surfaces, one notification, and a fourth surface added later
 * needs nothing here.
 */
class MentionedInComment extends Notification
{
    use Queueable;

    /** @param  Model  $comment  a TeamProjectComment, TeamTaskComment or TeamChannelMessage */
    public function __construct(public Model $comment, public ?User $author = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $author = $this->author ?? $this->comment->author;

        return [
            'title' => ($author?->name ?? 'Someone').' mentioned you',
            // The body, trimmed. Not the whole thing: the bell is a pointer,
            // and the conversation is where it is read.
            'message' => sprintf(
                'In %s: "%s"',
                $this->comment->conversationLabel(),
                Str::limit(trim(preg_replace('/\s+/', ' ', (string) $this->comment->body)), 140),
            ),
            'action_url' => $this->comment->conversationUrl(),
        ];
    }
}
