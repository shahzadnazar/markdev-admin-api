<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\IsTeamComment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The discussion on a project — private to the team doing the work.
 *
 * A CLIENT NEVER SEES THESE, even on their own project, and there is no toggle
 * that could make one visible. See IsTeamComment for why that is a rule rather
 * than a default.
 *
 * Visibility follows the work: a comment on a project you cannot see is a 404,
 * decided by Project::scopeVisibleTo — the same scope the project screens use,
 * so a conversation cannot be reachable where its project is not.
 */
class TeamProjectComment extends Model
{
    use Auditable, IsTeamComment, SoftDeletes {
        // Both traits define auditContext: Auditable as an empty default, and
        // IsTeamComment with what a comment row actually needs. The comment
        // one wins, explicitly, because a silent collision here would have
        // meant audit rows that name the actor and never the subject.
        IsTeamComment::auditContext insteadof Auditable;
    }

    protected $fillable = ['project_id', 'user_id', 'parent_id', 'body'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The project's team, and nobody else. */
    public function mentionableUsers()
    {
        return $this->project?->team?->members()->get(['users.id', 'users.name']) ?? collect();
    }

    /** Which project, so an audit row reads on its own. */
    protected function extraAuditContext(): array
    {
        return ['project_id' => $this->project_id];
    }
}
