<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Http\Request;

/**
 * The API side of the multi-select list filters.
 *
 * The portal's status filters tick several values now, so `?status=absent`
 * becomes `?status[]=absent&status[]=late`. Same three rules the admin panel's
 * FiltersByValues states, for the same reasons — an older app build still
 * sends a scalar and must keep working, an empty selection is no filter rather
 * than "match nothing", and a value the client was never offered is dropped
 * before it reaches the query.
 *
 * A separate file from the admin one on purpose: that trait is a controller
 * concern under Admin, and reaching across namespaces to borrow three lines
 * would couple two surfaces that have no other reason to move together. The
 * rule is small; the coupling would not be.
 */
trait FiltersByValues
{
    /**
     * The values ticked for one filter, minus anything not on the allowed list.
     *
     * @param  array<int, string>  $allowed
     * @return array<int, string>
     */
    protected function filterValues(Request $request, string $key, array $allowed): array
    {
        $raw = $request->query($key);

        if ($raw === null || $raw === '') {
            return [];
        }

        return collect(is_array($raw) ? $raw : [$raw])
            ->filter(fn ($value) => is_string($value) || is_int($value))
            ->map(fn ($value) => (string) $value)
            ->intersect($allowed)
            ->unique()
            ->values()
            ->all();
    }
}
