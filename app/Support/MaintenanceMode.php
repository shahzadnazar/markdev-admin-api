<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * The downtime switch: who is held out, and what they are told.
 *
 * ## Not `php artisan down`
 *
 * That blocks everyone, including the people fixing the problem; it needs a
 * bypass secret passed around out of band; and it cannot be turned on from a
 * form by the admin who notices the problem. This is a SETTING — a state of the
 * academy, not a state of the deployment — so it is a row an admin toggles and a
 * middleware reads.
 *
 * ## Who keeps working
 *
 * Staff. They are the ones doing the maintenance, so holding them out is holding
 * out the fix. Students and clients are out: a student mid-migration sees half a
 * curriculum, and a client is an outsider looking at a system in the middle of
 * being changed.
 *
 * ALLOWED IS THE EXPLICIT LIST, and blocked is everything else. Default-deny,
 * the same discipline as TeamWorkVisibility::seesOwner refusing an owner type
 * nobody has taught it about: a role added in a later phase is held out until
 * somebody decides it is staff. The alternative — listing who is blocked — would
 * let a new outsider role through on the day it is created, which is the
 * expensive direction of that mistake.
 *
 * The list is PortalHome::PANEL_ROLES, not a second copy of it. "Has a staff
 * panel" and "keeps working during maintenance" are the same question about the
 * same people, and this codebase's existing answer to the first one is that
 * constant. MaintenanceModeTest pins the verdict per role, so if the two ever
 * need to differ, that fails loudly rather than drifting.
 */
final class MaintenanceMode
{
    public const SWITCH_KEY = 'maintenance_mode';

    public const MESSAGE_KEY = 'maintenance_message';

    /**
     * What somebody blocked is told when nobody has written anything.
     *
     * A default rather than a hardcoded string in a view: the standing rule on
     * this project is that nothing user-facing is baked into a template. An
     * admin who types their own message replaces this; an admin in a hurry gets
     * something that is at least true and says what to do.
     */
    public const DEFAULT_MESSAGE = 'MarkDev is down for scheduled maintenance. Please try again shortly — '
        .'nothing you have submitted has been lost.';

    /** Whether downtime is on right now. */
    public static function isOn(): bool
    {
        // Setting::cached, not Cache::remember. The latter cost this project a
        // 30-second timeout once, and this is read on every student request.
        return (bool) Setting::cached(self::SWITCH_KEY);
    }

    /** The admin's own wording, or the default when they have written none. */
    public static function message(): string
    {
        $typed = trim((string) (Setting::cached(self::MESSAGE_KEY) ?? ''));

        return $typed !== '' ? $typed : self::DEFAULT_MESSAGE;
    }

    /**
     * Whether this person keeps working while downtime is on.
     *
     * Nobody, when nobody is signed in: an unauthenticated request cannot be
     * shown to be staff, so it is held out. The login routes are deliberately
     * NOT behind this middleware for exactly that reason — see the route file.
     */
    public static function allows(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasAnyRole(PortalHome::PANEL_ROLES);
    }

    /** Whether this person is held out right now. The one question callers ask. */
    public static function blocks(?User $user): bool
    {
        return self::isOn() && ! self::allows($user);
    }
}
