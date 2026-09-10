<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Http\Request;

/**
 * Reading a multi-select list filter off the query string.
 *
 * The admin list filters used to be single native selects — `?category=3`.
 * They tick several values now, so the same filter arrives as
 * `?category[]=3&category[]=5` and the query moves from `where` to `whereIn`.
 * That is three lines of the same normalising in about eighteen places, which
 * is how a rule drifts, so it lives here — the same reason FiltersTrashed
 * exists.
 *
 * Three things it has to get right:
 *
 * **An old link still works.** A bookmark or a link someone pasted into chat
 * carries `?category=3`, and breaking those is a real cost for no gain. A
 * scalar is normalised to a one-element array.
 *
 * **Empty means no filter, not "match nothing".** Ticking every box then
 * unticking them again shows the whole list. `whereIn('id', [])` matches no
 * rows, which would read as "there is nothing here" rather than "you have
 * chosen nothing" — so an empty result is returned as `[]` and every caller
 * guards with `->when($values, ...)`.
 *
 * **An unexpected value never reaches the query.** Callers pass the values
 * they are willing to accept, and anything else is dropped. That list is
 * deliberately the same one that fills the dropdown, which means a filter
 * cannot be talked into matching on something its own options never offered:
 * an instructor's category list is already narrowed to what they teach, so
 * `?category[]=99` for a category they cannot see drops out here rather than
 * quietly widening their view.
 *
 * Dropped, not refused — as with FiltersTrashed. A stale link with a since
 * deleted category id is not an attack, and a 422 on a list page tells someone
 * they did something wrong when they did not.
 */
trait FiltersByValues
{
    /**
     * The values ticked for one filter, as strings, minus anything unexpected.
     *
     * @param  array<int, string|int>  $allowed  the only values that may pass
     * @return array<int, string>
     */
    protected function filterValues(Request $request, string $key, array $allowed): array
    {
        $raw = $request->query($key);

        if ($raw === null || $raw === '') {
            return [];
        }

        // An old single-value URL, and the shape a plain <select> still posts.
        $values = is_array($raw) ? $raw : [$raw];

        $allowed = collect($allowed)->map(fn ($value) => (string) $value)->all();

        return collect($values)
            // Nested arrays and objects are not values anyone ticked. Cast only
            // what can be cast, so ?category[][]=1 cannot reach the intersect.
            ->filter(fn ($value) => is_string($value) || is_int($value))
            ->map(fn ($value) => (string) $value)
            ->intersect($allowed)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The same, as integers, for an id filter.
     *
     * @param  array<int, string|int>  $allowed
     * @return array<int, int>
     */
    protected function filterIds(Request $request, string $key, array $allowed): array
    {
        return array_map('intval', $this->filterValues($request, $key, $allowed));
    }
}
