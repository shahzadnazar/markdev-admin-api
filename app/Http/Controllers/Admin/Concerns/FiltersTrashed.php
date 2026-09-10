<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Http\Request;

/**
 * Who may look in the trash on a list screen.
 *
 * Every soft-deleting list here offers a "Trashed" filter, and the screens
 * that offer it reach more roles than the ones that can empty or restore it.
 * An instructor on Courses, or a manager on Courses, Users and Students, could
 * switch to a view of deleted rows whose every action would refuse them — an
 * empty room they were invited into.
 *
 * The permission names differ per screen and stay at each call site, because
 * they belong to the screen. What lives here is the one rule they share: the
 * `?trashed=1` in the URL is honoured only for someone who could act on what
 * it shows.
 *
 * ## Ignored, not refused
 *
 * A stray query string is not an attack — it is a copied link, a bookmark from
 * a colleague with more permissions, a back button. Answering it with a 403 on
 * a list page tells someone they did something wrong when they did not. They
 * get the ordinary list instead, which is the thing they came for.
 *
 * Presentation only. The restore, delete and force-delete routes carry their
 * own `can:` middleware and are untouched by any of this: a hidden filter is
 * not a permission, and the server still refuses the write whether or not a
 * checkbox was ever rendered.
 */
trait FiltersTrashed
{
    /**
     * Whether this viewer may see the trash on this screen at all.
     *
     * Drives the checkbox. Any one of the given permissions is enough — a role
     * that can restore but not delete still has business in there.
     */
    protected function mayViewTrash(Request $request, string ...$permissions): bool
    {
        return $request->user()?->canAny($permissions) ?? false;
    }

    /** Whether the trashed list was asked for by someone entitled to it. */
    protected function showingTrashed(Request $request, string ...$permissions): bool
    {
        return $request->string('trashed')->toString() === '1'
            && $this->mayViewTrash($request, ...$permissions);
    }
}
