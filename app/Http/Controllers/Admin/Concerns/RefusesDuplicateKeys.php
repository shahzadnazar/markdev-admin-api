<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The form half of HasNormalisedKey: one handle, one row.
 *
 * ## Why the check has two arms
 *
 * The stored key comes first, because it is what the unique index sits on, so
 * the form and the database agree on what "the same" means and the lookup is
 * indexed. Then the typed column, normalised in SQL — because a stored key CAN
 * be stale: a row written before the key column existed, one written before the
 * rule last changed, or one written straight through the query builder. A check
 * that trusted the key alone would wave that duplicate through, and the unique
 * index would then refuse the insert with a 500 instead of a message.
 *
 * ## Why it lives here
 *
 * Teams and projects need the identical rule over different columns, and the
 * second copy of a query like this is where the two quietly stop agreeing. The
 * columns come from the model, via HasNormalisedKey, so this file names none of
 * them.
 */
trait RefusesDuplicateKeys
{
    /**
     * Refuse a handle another live row already holds.
     *
     * Soft-deleted rows do not count: their key was cleared on delete, and the
     * global scope drops them from the query — a cancelled project's code is
     * free again, by design.
     *
     * @param  class-string<Model>  $modelClass  a model using HasNormalisedKey
     * @param  Model|null  $existing  the row being edited, if any
     */
    protected function assertKeyIsFree(string $modelClass, string $value, ?Model $existing, string $field, string $message): void
    {
        $keyColumn = $modelClass::keyColumn();
        $sourceColumn = $modelClass::keySourceColumn();

        // Editing a row without changing its handle always passes. Rows created
        // before this rule existed may already share one, and being unable to
        // fix such a row's other fields because of its own name helps nobody.
        if ($existing !== null
            && $modelClass::normaliseKey($existing->{$sourceColumn}) === $modelClass::normaliseKey($value)) {
            return;
        }

        $key = $modelClass::normaliseKey($value);

        $taken = $modelClass::query()
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->getKey()))
            ->where(function ($query) use ($key, $keyColumn, $sourceColumn) {
                $query->where($keyColumn, $key)
                    // The column name is a model constant, never request input.
                    ->orWhereRaw("lower(replace({$sourceColumn}, ' ', '')) = ?", [$key]);
            })
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
