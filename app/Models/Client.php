<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Someone MarkDev does work for.
 *
 * ADMIN EYES ONLY. Every screen that touches this model is gated on
 * `clients.*`, which only super-admin and admin hold. A team lead never sees a
 * client's name, their company, their contact details or their id — not in a
 * list, not in a form, not in a URL. They identify a project by its name and
 * its code, which is the whole reason a project carries a code.
 */
class Client extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'name',
        'company',
        'email',
        'phone',
        'address',
        'notes',
        'user_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /* ----------------------------- Relations ------------------------------ */

    /**
     * The login this client signs in with, once they have one.
     *
     * Unused in phase 2; the client portal is phase 6. See the migration for
     * why the column exists already.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /* ------------------------------- Scopes -------------------------------- */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Alphabetical; there is no meaningful order to a client list. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('name')->orderBy('id');
    }

    /* ------------------------------- Helpers ------------------------------- */

    /**
     * How many projects point at this client, trashed ones included.
     *
     * Trashed ones count because they still hold the foreign key: deleting the
     * client under them would leave rows pointing at nothing, and a restored
     * project would come back belonging to a client who no longer exists.
     */
    public function projectCount(): int
    {
        return $this->projects()->withTrashed()->count();
    }

    /** "Acme Ltd — Jane Doe", or just the name when there is no company. */
    public function label(): string
    {
        return $this->company ? $this->company.' — '.$this->name : $this->name;
    }
}
