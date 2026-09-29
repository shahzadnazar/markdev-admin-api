<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * What a configurable status list — task or project — has in common.
 *
 * ## Label and behaviour are not the same column
 *
 * `label` is the academy's wording. It may be renamed at any time, and it means
 * nothing to the code. `behaviour` is one of a fixed set, declared as the host
 * model's BEHAVIOURS constant, and it is the ONLY thing code is allowed to
 * branch on.
 *
 * That separation is the reason these tables exist. Later phases ask "is this
 * task blocked?" — blocked days are excluded from variance — and "is this
 * project closed?" — a closed project records actual days. If those questions
 * were asked of the label, an admin renaming "Blocked" to "Waiting on client"
 * would silently break the variance calculation with no error anywhere. It is
 * the same trap as matching the attendance status 'absent' by its display name.
 *
 * ## Every behaviour keeps at least one active status
 *
 * A behaviour with no active status is a board column nothing can be moved
 * into, and a question the code asks that nothing can ever answer. The rule is
 * checked after the write, inside the transaction, so no save, toggle or delete
 * can route around it by being a path somebody forgot to add a check to.
 */
trait IsWorkflowStatus
{
    /** Only the statuses on offer. Retired ones keep the rows that point at them. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The order the admin arranged them in. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** The behaviour's own wording, for a form or a table cell. */
    public function behaviourLabel(): string
    {
        return static::BEHAVIOURS[$this->behaviour] ?? $this->behaviour;
    }

    /**
     * Behaviours that currently have no ACTIVE status, in declared order.
     *
     * Reads the table as it stands, so the caller writes first and asks
     * afterwards — inside a transaction, where an answer it does not like
     * rolls the write back.
     *
     * @return array<int, string>
     */
    public static function behavioursWithoutActive(): array
    {
        $covered = static::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('behaviour')
            ->all();

        return array_values(array_diff(array_keys(static::BEHAVIOURS), $covered));
    }
}
