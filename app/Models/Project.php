<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasNormalisedKey;
use App\Models\Concerns\ScopesToDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A piece of client work, delivered by exactly one team.
 *
 * ## Who sees what
 *
 * Super-admin and admin see every project and every field. A team lead or team
 * member sees only projects belonging to a team they are a MEMBER of — not the
 * team they lead, membership, because a lead may be a member of several teams
 * and leads exactly one. (Lead-must-be-a-member is enforced when a team is
 * saved, so scoping on membership already includes the team they run.)
 *
 * What a team person must never see is the CLIENT and the MONEY: not the
 * client's name, company, contact details or id, and not the contract value or
 * its currency. That is why a project carries a code — it is the only handle
 * they have for telling two projects with the same name apart.
 *
 * ## Dates
 *
 * `start_date` and `due_date` are cast `date:Y-m-d` and are only ever queried
 * through ScopesToDay. Equality on a date-cast column has cost this project ten
 * incidents; DateColumnGuardTest derives its column list from these casts, so
 * the moment they exist every banned form is watched for across app/, database/
 * and tests/.
 *
 * ## Status
 *
 * Nothing here matches a status by LABEL. An admin may rename "On Hold" to
 * "Waiting on client" whenever they like; the behaviour beside it is what the
 * code reads, which is what stops a rename silently breaking the day counts.
 */
class Project extends Model
{
    use Auditable, HasNormalisedKey, ScopesToDay, SoftDeletes;

    /**
     * The permission that unlocks the client and the money.
     *
     * `clients.view` rather than a new name of its own, deliberately: the
     * thing being protected IS who the project is for and what they are paying,
     * and anybody entitled to open the client list already knows both. A second
     * permission would let the two drift, and the first time they disagreed the
     * money would be showing to someone the client list refuses.
     *
     * Only super-admin and admin hold it. The same string is the gate in the
     * views; it is named here so the query scope and the markup cannot part
     * company.
     *
     * ## ONE PERMISSION, TWO QUESTIONS — and that is a choice
     *
     * This constant answers both of these:
     *
     *   WHICH ROWS does this viewer see?      scopeVisibleTo, below
     *   ARE the client and the value SHOWN?   the `@can` in the views
     *
     * They are the same answer today only because the only people holding
     * `clients.view` are the two roles that see everything anyway. They stop
     * being the same answer the first time somebody should oversee EVERY project
     * without being shown what each one is worth — an auditor, a delivery
     * manager, a second-tier admin. That is a real role, not a hypothetical one,
     * and this is deliberately not built for it yet.
     *
     * THE SPLIT, when it is needed. Add a second constant beside this one:
     *
     *     public const FULL_VIEW_PERMISSION = 'clients.view';   // which rows
     *     public const MONEY_VIEW_PERMISSION = 'clients.view';  // which fields
     *
     * both pointing at the same permission, so nothing changes on the day they
     * are introduced. Then move every `@can(Project::FULL_VIEW_PERMISSION)` in a
     * VIEW and every `->with('client')` in a controller onto the second one, and
     * repoint it at a new permission. The order matters: two constants with one
     * value first, callers moved second, value changed last — each step is
     * verifiable on its own, and the middle step is the one a test can prove is
     * complete. Doing it in one commit means the grep and the behaviour change
     * together and nothing tells you which callers you missed.
     */
    public const FULL_VIEW_PERMISSION = 'clients.view';

    /**
     * Status behaviours whose projects are counting down.
     *
     * `paused` is absent on purpose: a paused project is not consuming its
     * schedule, so counting its days would charge a team for time it was told
     * to stop working. The two closed behaviours are absent because a finished
     * project has no days left to have.
     */
    public const COUNTING_BEHAVIOURS = ['planning', 'running'];

    protected $fillable = [
        'name',
        'code',
        'client_id',
        'team_id',
        'project_status_id',
        'start_date',
        'due_date',
        'contract_value',
        'currency',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'contract_value' => 'decimal:2',
        ];
    }

    /** The code is the handle; `code_key` is the normalised form it is unique on. */
    public static function keyColumn(): string
    {
        return 'code_key';
    }

    public static function keySourceColumn(): string
    {
        return 'code';
    }

    /** ScopesToDay addresses `due_date` unless a call names the other one. */
    public static function dayColumn(): string
    {
        return 'due_date';
    }

    /* ----------------------------- Relations ------------------------------ */

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'project_status_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The team's discussion on this project.
     *
     * Private to the team doing the work. A client never sees any of it, and
     * there is no per-comment flag that could change that — see
     * App\Models\Concerns\IsTeamComment.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TeamProjectComment::class);
    }

    /** Files attached to this project, on the private disk. */
    public function files(): MorphMany
    {
        return $this->morphMany(TeamFile::class, 'owner');
    }

    /**
     * What the client has asked about this project.
     *
     * The ONE surface a client writes to, and not a second discussion: one
     * question, one answer, and the team talks it over in `comments` above —
     * which the client never sees. See ClientQuestion.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(ClientQuestion::class);
    }

    /* ------------------------------- Scopes -------------------------------- */

    /**
     * The projects this person may see at all.
     *
     * The ONE place row visibility is decided. Every list, page, form and count
     * goes through it, so a screen added later cannot quietly serve a team
     * person somebody else's work.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->can(static::FULL_VIEW_PERMISSION)) {
            return $query;
        }

        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        // Membership, not teams.team_lead_id: a lead leads one team and may be
        // a member of several, and they are entitled to all of them.
        return $query->whereIn('team_id', $user->teams()->select('teams.id'));
    }

    /** Projects whose schedule is actually running. */
    public function scopeCountingDays(Builder $query): Builder
    {
        return $query->whereHas('status', fn (Builder $status) => $status->whereIn('behaviour', static::COUNTING_BEHAVIOURS));
    }

    /** Newest first: a project list is read from the top. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }

    /* ------------------------------- Helpers ------------------------------- */

    /** Branching on behaviour, never on the label an admin may rename. */
    public function behaviour(): ?string
    {
        return $this->status?->behaviour;
    }

    public function isPaused(): bool
    {
        return $this->behaviour() === 'paused';
    }

    /** Whether this project's schedule is running at all. */
    public function isCountingDays(): bool
    {
        return in_array($this->behaviour(), static::COUNTING_BEHAVIOURS, true);
    }

    /**
     * Days left until the due date, or null when there is no such number.
     *
     * Null for a paused project — its days do not count, so it has no
     * remaining figure rather than a stale one — and null for a closed one,
     * which has no schedule left to run. Null also when no due date was set.
     * Every caller renders null as a dash; nothing anywhere invents a zero.
     */
    public function daysRemaining(): ?int
    {
        if ($this->due_date === null || ! $this->isCountingDays()) {
            return null;
        }

        return (int) Carbon::today()->diffInDays(Carbon::parse($this->due_date)->startOfDay(), false);
    }

    /**
     * Days spent so far, or null when they are not being counted.
     *
     * Same rule as above and for the same reason: phase 3's variance score
     * compares this against an estimate, and a paused project contributing
     * elapsed days would make every team that was told to wait look late.
     */
    public function elapsedDays(): ?int
    {
        if ($this->start_date === null || ! $this->isCountingDays()) {
            return null;
        }

        return (int) Carbon::parse($this->start_date)->startOfDay()->diffInDays(Carbon::today(), false);
    }
}
