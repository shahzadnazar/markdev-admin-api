<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * A human-typed handle that is unique case-insensitively, with a DB index
 * behind it — a team's name, a project's code.
 *
 * THE PROBLEM THIS SOLVES TWICE. "Web Team" and "web team" are one name and
 * "PRJ-014" and " prj-014 " are one code, but a unique index on the typed
 * column would let both pairs through. So a second, normalised column carries
 * the index: derived on save, never posted, never in $fillable.
 *
 * AND WHY IT IS NULLABLE. A soft delete goes through the query builder rather
 * than save(), so the saving hook never sees it — the deleted hook clears the
 * key instead. Without that, an index on the typed column alone would reserve
 * a dismantled team's name, or a cancelled project's code, for ever; reusing
 * PRJ-014 for the re-signed version of the same job is the ordinary thing to
 * want.
 *
 * Hosts declare which two columns they mean. The controller side of the same
 * rule is RefusesDuplicateKeys, which asks THIS class what to compare, so the
 * form and the database cannot disagree about what "the same" means.
 */
trait HasNormalisedKey
{
    /** The normalised column the unique index sits on. */
    abstract public static function keyColumn(): string;

    /** The typed column it is derived from. */
    abstract public static function keySourceColumn(): string;

    public static function bootHasNormalisedKey(): void
    {
        static::saving(function (Model $model): void {
            $model->{static::keyColumn()} = static::normaliseKey($model->{static::keySourceColumn()});
        });

        static::deleted(function (Model $model): void {
            if (! method_exists($model, 'trashed') || ! $model->trashed()) {
                return;
            }

            $model->newQueryWithoutScopes()->whereKey($model->getKey())->toBase()
                ->update([static::keyColumn() => null]);

            $model->{static::keyColumn()} = null;
            $model->syncOriginalAttribute(static::keyColumn());
        });
    }

    /**
     * The comparable form: case-folded, every space removed.
     *
     * One implementation, so the two hosts cannot drift apart — and so the SQL
     * half of the uniqueness check can mirror it exactly.
     */
    public static function normaliseKey(?string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', (string) $value));
    }
}
