<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasNormalisedKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A group of MarkDev staff that client work is assigned to.
 *
 * A team has exactly ONE lead, and that lead is a member of the team — a lead
 * who is not in the team is a manager of it, which is a different thing this
 * does not model. A member may belong to several teams: someone who designs for
 * two products is on both, and duplicating them as two people would give them
 * two task lists.
 *
 * NOTHING HERE IS AN ACADEMY CONCEPT. A team is not a category, a class or a
 * course; no academy screen reads this table and this table reads none of
 * theirs. The two share the `users` table and nothing else.
 */
class Team extends Model
{
    use Auditable, HasNormalisedKey, SoftDeletes;

    protected $fillable = [
        'name',
        'team_lead_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * `name_key` is the normalised name the unique index sits on; `name` is
     * what was typed. The hooks that keep them in step, and that release the
     * name when a team is soft-deleted, are in HasNormalisedKey — shared with
     * a project's code, which needs the identical treatment for the identical
     * reason.
     */
    public static function keyColumn(): string
    {
        return 'name_key';
    }

    public static function keySourceColumn(): string
    {
        return 'name';
    }

    /**
     * The comparable form of a name: case-folded, whitespace removed.
     *
     * Kept as its own name because a team's handle IS its name; it delegates,
     * so there is still only one implementation.
     */
    public static function normaliseName(?string $name): string
    {
        return static::normaliseKey($name);
    }

    /* ----------------------------- Relations ------------------------------ */

    /** The one person accountable for this team's work. */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_lead_id');
    }

    /**
     * Everyone on the team, the lead included.
     *
     * Deactivating or soft-deleting a USER does not touch these rows: their
     * past work still has to say which team did it. The pivot's cascade only
     * fires on a force delete, when the account is genuinely gone.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members')->withTimestamps();
    }

    /**
     * The client work pointed at this team.
     *
     * The foreign key restricts on delete, so a team with projects cannot be
     * erased out from under them.
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /* ------------------------------- Scopes -------------------------------- */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Alphabetical: there is no meaningful order to a list of teams. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('name')->orderBy('id');
    }

    /* ------------------------------- Helpers ------------------------------- */

    /** Whether a user id is on this team. */
    public function hasMember(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return $this->members()->whereKey($userId)->exists();
    }

    /**
     * Whether the stored lead is one of the stored members.
     *
     * Read-only: the rule is enforced where the member list and the lead are
     * posted together, because a `saving` hook cannot see a pivot that has not
     * been synced yet. This is how a screen or a test asks about a row that is
     * already in the table.
     */
    public function leadIsMember(): bool
    {
        return $this->team_lead_id !== null && $this->hasMember($this->team_lead_id);
    }
}
