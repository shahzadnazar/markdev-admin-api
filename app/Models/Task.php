<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopesToDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

/**
 * A unit of work a team does.
 *
 * ## Two clocks, and they never touch
 *
 * `days_allowed` HERE is what the admin promised for the task. It is what the
 * parent and the project are judged against.
 *
 * What a PERSON is judged against is their stint's `days_allowed`, on
 * TaskAssignment. A member is never measured against a parent's allowance: the
 * parent is the lead's promise, the stint is theirs, and the gap between them
 * is the lead's planning problem.
 *
 * Parts are ALLOWED to total more or less than their parent. Nothing blocks it
 * and nothing warns on submit; the totals are shown as plain fact on the parent
 * and the project, because that gap is the signal a lead needs and is none of
 * a member's business.
 *
 * ## One level of splitting
 *
 * A task may be split into parts; a part may not be split again. Enforced in
 * `saving`, because a form is not the only way a row gets written.
 *
 * ## Visibility
 *
 * One scope, `visibleTo`, and no screen writes its own filter — the same rule
 * Project::scopeVisibleTo established. Three tiers, using the permissions the
 * portal already grants rather than minting new ones:
 *
 *   clients.view  (super-admin, admin)  every task
 *   teams.view    (team-lead)           every task of teams they are a MEMBER of
 *   otherwise     (team)                tasks they hold or held a stint on
 */
class Task extends Model
{
    use Auditable, ScopesToDay, SoftDeletes;

    /** Sees every task. Held by super-admin and admin — see Project. */
    public const FULL_VIEW_PERMISSION = 'clients.view';

    /** Sees their teams' tasks. Held by team-lead, not by team. */
    public const TEAM_VIEW_PERMISSION = 'teams.view';

    protected $fillable = [
        'team_id',
        'project_id',
        'parent_id',
        'task_status_id',
        'title',
        'description',
        'days_allowed',
        'started_on',
        'due_date',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'started_on' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'days_allowed' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public static function dayColumn(): string
    {
        return 'due_date';
    }

    protected static function booted(): void
    {
        static::saving(function (self $task): void {
            $task->assertOneLevelDeep();
            $task->assertTeamMatchesProject();
        });
    }

    /* ------------------------------- Rules --------------------------------- */

    /**
     * A part may not be split again.
     *
     * Checked on the row being saved (is my parent itself a part?) and on the
     * row being made a parent (do I already have a parent?). Both directions,
     * because either write creates the same three-level tree.
     */
    protected function assertOneLevelDeep(): void
    {
        if ($this->parent_id === null) {
            return;
        }

        $parent = static::withTrashed()->find($this->parent_id);

        if ($parent?->parent_id !== null) {
            throw ValidationException::withMessages([
                'parent_id' => 'A part cannot be split again. Split the original task into more parts instead.',
            ]);
        }

        if ($this->exists && $this->parts()->exists()) {
            throw ValidationException::withMessages([
                'parent_id' => 'This task already has parts, so it cannot become a part of another task.',
            ]);
        }
    }

    /**
     * A task on a project belongs to that project's team.
     *
     * Enforced here rather than only in the form, because a hand-posted id, a
     * seeder or a later screen would otherwise put a Web task on a Graphics
     * project and give its board two homes.
     */
    protected function assertTeamMatchesProject(): void
    {
        if ($this->project_id === null) {
            return;
        }

        $project = Project::withTrashed()->find($this->project_id);

        if ($project !== null && (int) $project->team_id !== (int) $this->team_id) {
            throw ValidationException::withMessages([
                'project_id' => 'That project belongs to another team. A task on a project is done by the project\'s team.',
            ]);
        }
    }

    /* ----------------------------- Relations ------------------------------ */

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'task_status_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class)->orderBy('started_on')->orderBy('id');
    }

    /** The stint currently carrying the task, if anyone is. */
    public function openAssignment(): HasOne
    {
        return $this->hasOne(TaskAssignment::class)->whereNull('ended_on')->latestOfMany();
    }

    public function statusPeriods(): HasMany
    {
        return $this->hasMany(TaskStatusPeriod::class)->orderBy('started_on')->orderBy('id');
    }

    /* ------------------------------- Scopes -------------------------------- */

    /** The ONE row filter. Nothing else may narrow a task list. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->can(static::FULL_VIEW_PERMISSION)) {
            return $query;
        }

        if ($user->can(static::TEAM_VIEW_PERMISSION)) {
            // Membership, not the team they lead: a lead may be a member of
            // several teams and is entitled to all of them.
            return $query->whereIn('team_id', $user->teams()->select('teams.id'));
        }

        // A member sees the work they hold or held. Not their team's board —
        // somebody else's stint is somebody else's business.
        return $query->whereHas('assignments', fn (Builder $stint) => $stint->where('user_id', $user->getKey()));
    }

    /** Only tasks that are not parts — what a board column and a list show. */
    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderByDesc('id');
    }

    /* ------------------------------- Helpers ------------------------------- */

    /** Branching on behaviour, never on a label an admin may rename. */
    public function behaviour(): ?string
    {
        return $this->status?->behaviour;
    }

    public function isBlocked(): bool
    {
        return $this->behaviour() === 'blocked';
    }

    public function isDone(): bool
    {
        return $this->behaviour() === 'done';
    }

    public function isPart(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * What the parts add up to against what the parent was given.
     *
     * Plain fact, derived, stored nowhere. A lead reads it as a planning
     * signal; it never reaches a member's score, because a member is scored on
     * their own stint and nothing else.
     *
     * @return array{parts: int, allowed: int, promised: int, matches: bool}|null
     */
    public function splitSummary(): ?array
    {
        if ($this->isPart()) {
            return null;
        }

        $parts = $this->parts;

        if ($parts->isEmpty()) {
            return null;
        }

        $allowed = (int) $parts->sum('days_allowed');

        return [
            'parts' => $parts->count(),
            'allowed' => $allowed,
            'promised' => (int) $this->days_allowed,
            'matches' => $allowed === (int) $this->days_allowed,
        ];
    }

    public function auditContext(): array
    {
        return ['title' => $this->title, 'team_id' => $this->team_id];
    }
}
