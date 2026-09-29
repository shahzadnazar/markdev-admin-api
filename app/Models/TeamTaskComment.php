<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\IsTeamComment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A comment on a task, so the conversation stays attached to the work.
 *
 * NEVER CLIENT-VISIBLE, and there is no toggle — see IsTeamComment.
 *
 * Visibility follows the task's own scope, which is narrower than a project's:
 * a team member sees the tasks they hold a stint on, so they see the comments
 * on those and no others.
 */
class TeamTaskComment extends Model
{
    use Auditable, IsTeamComment, SoftDeletes {
        // Both traits define auditContext: Auditable as an empty default, and
        // IsTeamComment with what a comment row actually needs. The comment
        // one wins, explicitly, because a silent collision here would have
        // meant audit rows that name the actor and never the subject.
        IsTeamComment::auditContext insteadof Auditable;
    }

    protected $fillable = ['task_id', 'user_id', 'parent_id', 'body'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** Which task, so an audit row reads on its own. */
    protected function extraAuditContext(): array
    {
        return ['task_id' => $this->task_id];
    }

    /** The task's team, and nobody else. */
    public function mentionableUsers()
    {
        return $this->task?->team?->members()->get(['users.id', 'users.name']) ?? collect();
    }

    /* ---------------------------- Notifications ---------------------------- */

    public function conversationLabel(): string
    {
        return 'the task "'.($this->task?->title ?? 'a task').'"';
    }

    public function conversationUrl(): string
    {
        return $this->task === null
            ? route('admin.tasks.index', absolute: false)
            : route('admin.tasks.show', $this->task, absolute: false);
    }

    /**
     * The task, which is a NARROWER question than its team.
     *
     * Mentionable here is the whole team; visible is the members who hold or
     * held a stint on this task. The gap between those two is exactly what
     * PortalNotifier is checking, and it is the case that bites.
     */
    public function notificationWork(): ?Model
    {
        return $this->task;
    }
}
