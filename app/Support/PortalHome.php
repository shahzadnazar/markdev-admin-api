<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Where to send someone who has just arrived — signed in, at `/`, or handed
 * back by Breeze after a login, a registration or an email verification.
 *
 * ## Why this exists
 *
 * Every one of those paths used to redirect to `admin.dashboard`, which lives
 * inside the academy route group. A team lead, a team member or a client is
 * refused at that group's door — before `can:dashboard.view` is even
 * consulted — so signing in answered them with a 403, which reads as a broken
 * account rather than as an account without screens yet.
 *
 * ## Resolved by permission, not by role name
 *
 * A role name says who someone is; a permission says what they can open, which
 * is the actual question. Resolving by permission also means a role added in a
 * later phase lands correctly the moment it is granted something, with no
 * change here.
 *
 * ## The order IS the precedence
 *
 * DESTINATIONS is most senior first and the first match wins, the same
 * discipline as PortalLabel::LABELS. Super-admin and admin hold every
 * permission there is, so the academy dashboard has to come first or they would
 * land on a team screen.
 *
 * ## Which file is authoritative
 *
 * PORTALLABEL IS. It is the existing statement of which portal a person with
 * several roles belongs to, and its map order is that decision written down.
 * This map is ordered to agree with it — every academy role sits above every
 * team one in both — and if the two ever disagree about a multi-role user, this
 * file is the one that is wrong and the one to fix. What a portal is called and
 * where its front door is are the same question answered twice; they are
 * separate files only because one is keyed by role and one by permission.
 *
 * ## Phases 2 to 7
 *
 * A destination whose route does not exist yet is skipped rather than returned,
 * so the entry can be written now and lights up on its own the phase the screen
 * lands. That is why `projects` and `tasks` are already listed: a team member
 * gets the no-portal page today and their project list the day it ships, with
 * nothing to remember here.
 */
class PortalHome
{
    /**
     * Permission => the route it entitles someone to land on, most senior first.
     *
     * @var array<string, string>
     */
    public const DESTINATIONS = [
        // The academy. Held by super-admin, admin, manager and instructor, and
        // first because the two roles that hold everything belong here.
        'dashboard.view' => 'admin.dashboard',

        // The team portal, in the order a team person would rank them: the team
        // they run, then the work it is on, then their own task list.
        'teams.view' => 'admin.teams.index',
        'projects.view' => 'admin.projects.index',
        'tasks.view' => 'admin.tasks.index',
    ];

    /**
     * The roles that have a panel, most senior first.
     *
     * Only for `gate()` below. The DESTINATIONS map is resolved by permission
     * and stays that way; these names are the second half of the door, for the
     * same reason the academy and team groups name their roles beside their
     * permissions — a role whose permissions were customised must still get
     * through a door that is about having a panel at all, not about one screen.
     *
     * `client` is deliberately absent, and `student` too: neither has a panel,
     * so neither has a topbar to offer them anything.
     *
     * @var array<int, string>
     */
    public const PANEL_ROLES = ['super-admin', 'admin', 'manager', 'instructor', 'team-lead', 'team'];

    /** Signed in, with nowhere to be. A page, not a 403 — see its view. */
    public const NONE = 'portal.unavailable';

    /** Nobody is signed in. Returned so callers have one expression, not two. */
    public const GUEST = 'login';

    /**
     * The route name this person should land on.
     *
     * Every destination must be reachable by anyone holding its permission: the
     * gate on the way in is what decides, and sending someone to a screen their
     * own permission does not open is the bug this class exists to stop.
     */
    public static function for(?User $user): string
    {
        if ($user === null) {
            return static::GUEST;
        }

        foreach (static::DESTINATIONS as $permission => $route) {
            if (! $user->can($permission)) {
                continue;
            }

            // The phase that owns this screen has not shipped it yet. Skipped
            // rather than returned, because route() on a name that does not
            // exist throws, and a 500 at login is worse than the 403 was.
            if (! Route::has($route)) {
                continue;
            }

            return $route;
        }

        return static::NONE;
    }

    /**
     * The middleware for things EVERY panel user reaches — the topbar.
     *
     * The bell and the notifications list are not academy screens and not team
     * screens; they belong to whoever has a panel. They used to sit inside the
     * academy group, whose door refuses a team-lead and a team member, so the
     * bell was drawn for them by the shared layout and answered their click
     * with a 403 — a control that is offered and then refuses, the same shape
     * as the ungated Notes item fixed in b27d246.
     *
     * Derived from DESTINATIONS rather than written out a fifth time: the
     * permissions that entitle somebody to LAND somewhere are exactly the
     * permissions that mean they have a panel, so the two cannot drift. Adding
     * a portal in a later phase widens this door the moment its destination is
     * listed, with nothing to remember here.
     *
     * This is the union of the two existing gates and nothing more. It admits
     * nobody who could not already open a panel screen.
     */
    public static function gate(): string
    {
        return 'role_or_permission:'.implode('|', [
            ...static::PANEL_ROLES,
            ...array_keys(static::DESTINATIONS),
        ]);
    }
}
