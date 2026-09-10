<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * What to call this panel, for the person looking at it.
 *
 * The sidebar said "Admin Portal" to everyone, which an instructor is not.
 * The label follows the viewer's role now, and lives here rather than as a
 * run of @if in Blade: a fifth role added to the seeder would have fallen
 * through those silently and gone on calling itself Admin.
 *
 * ## Precedence
 *
 * A user can hold more than one role, so the answer has to be decided rather
 * than left to whichever row the database returns first. LABELS is ordered
 * most senior first and the first match wins, so someone who is both an
 * instructor and an admin is shown Admin — the more senior of the two, which
 * is also the wider set of screens they can actually reach.
 *
 * ## A role with no label
 *
 * Not a blank, and not a lie. An unmapped role is title-cased into its own
 * label — a future `registrar` becomes "Registrar Portal" — because a
 * generated name is right far more often than "Admin" would be, and it is
 * obvious enough on screen that someone will come and add a proper one.
 * Where several are unmapped the alphabetically first is used, so the answer
 * is stable between requests. Someone with no roles at all gets the bare
 * "Portal".
 *
 * A label only. Nothing is gated on it, and nothing should be: what a screen
 * calls itself and who may open it are different questions, decided in
 * different places.
 */
class PortalLabel
{
    /**
     * Role => label, most senior first. The order IS the precedence.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        'super-admin' => 'Super Admin Portal',
        'admin' => 'Admin Portal',
        'manager' => 'Manager Portal',
        'instructor' => 'Instructor Portal',
    ];

    /** What the panel calls itself with nobody signed in — the login screen. */
    public const GUEST = 'Admin Portal';

    /** The last resort: signed in, but holding no role at all. */
    public const UNKNOWN = 'Portal';

    public static function for(?User $user): string
    {
        if ($user === null) {
            return static::GUEST;
        }

        $roles = $user->getRoleNames();

        foreach (static::LABELS as $role => $label) {
            if ($roles->contains($role)) {
                return $label;
            }
        }

        // Sorted, so a user with two unmapped roles gets the same answer on
        // every request rather than whichever the database happened to hand
        // back first.
        $unmapped = $roles->sort()->first();

        return $unmapped === null
            ? static::UNKNOWN
            : Str::of($unmapped)->replace(['-', '_'], ' ')->title()->append(' Portal')->toString();
    }
}
