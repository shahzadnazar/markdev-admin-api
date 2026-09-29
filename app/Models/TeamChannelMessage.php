<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\IsTeamComment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The one cross-team channel: where every team talks to every other.
 *
 * Admin announcements land here too rather than in a second place, because a
 * second place is a place people stop reading. They are pinned above the rest
 * and drawn distinctly, and only an admin or super-admin may post one.
 *
 * NEVER CLIENT-VISIBLE, and there is no toggle — see IsTeamComment.
 */
class TeamChannelMessage extends Model
{
    use Auditable, IsTeamComment, SoftDeletes {
        // Both traits define auditContext: Auditable as an empty default, and
        // IsTeamComment with what a comment row actually needs. The comment
        // one wins, explicitly, because a silent collision here would have
        // meant audit rows that name the actor and never the subject.
        IsTeamComment::auditContext insteadof Auditable;
    }

    protected $fillable = ['user_id', 'parent_id', 'body', 'is_announcement'];

    protected function casts(): array
    {
        return ['is_announcement' => 'boolean'];
    }

    /**
     * Anybody in the team portal.
     *
     * The channel is the one surface where a mention crosses a team boundary,
     * which is the point of having it: somebody on Web needs to be able to ask
     * somebody on Graphics a question.
     */
    public function mentionableUsers()
    {
        return User::query()
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', ['super-admin', 'admin', 'team-lead', 'team']))
            ->get(['id', 'name']);
    }

    /* ---------------------------- Notifications ---------------------------- */

    public function conversationLabel(): string
    {
        return 'the team channel';
    }

    public function conversationUrl(): string
    {
        return route('admin.team-channel.index', absolute: false);
    }

    /**
     * Nothing — and that is the point.
     *
     * The channel is the one surface with no project or task behind it, so
     * there is no piece of work to check a recipient against. PortalNotifier
     * still asks whether they are in the team portal at all, which is the whole
     * of the channel's own gate.
     */
    public function notificationWork(): ?Model
    {
        return null;
    }

    /**
     * Announcements first, then the ordinary conversation.
     *
     * `reorder()` first, because the threads scope has already asked for id
     * order and an ordering added after it would only be a tie-breaker — which
     * is to say, no pinning at all.
     */
    public function scopePinnedFirst(Builder $query): Builder
    {
        return $query->reorder()->orderByDesc('is_announcement')->orderBy('id');
    }
}
