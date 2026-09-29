<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\Contracts\CarriesItsSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * The ONE door every team-portal notification goes through.
 *
 * ## Why there is no new table
 *
 * Attendance, leave and comments each got their own table in phases 4 and 5,
 * and this deliberately does not. Those tables are QUERIED ACROSS a population:
 * "the register for this team", "the discussion on this project" — so a shared
 * table would have meant one wrong join away from a team person reading a
 * student's row. Laravel's `notifications` table is never queried that way. It
 * is keyed on the notifiable, every screen reads
 * `$user->notifications()`, and there is no cross-population query to get
 * wrong. No population to leak, no second table.
 *
 * ## NOBODY IS NOTIFIED ABOUT WORK THEY CANNOT SEE
 *
 * This is the whole reason this class exists rather than `$user->notify(...)`
 * scattered through five controllers. A notification naming a project a person
 * cannot open is a leak with a friendly face: the title alone tells them a
 * project exists, what it is called, and that their colleague is on it — which
 * is exactly what phase 2 spent a scope and a gate keeping from them. It would
 * also be the one way this feature could undo that work, because a notification
 * is written by the WRITER's request and read by the RECIPIENT's eyes, and the
 * recipient's permissions are never in scope at the moment it is sent unless
 * somebody asks.
 *
 * So `mayBeTold` asks, through TeamWorkVisibility — the same Project and Task
 * scopes the screens use. A recipient who fails it is not an error and not a
 * warning: nothing is written, and the write that caused it carries on.
 *
 * ## In the portal only
 *
 * Database channel, nothing else. There is no mail driver here, no queue, and
 * no per-person delivery preference; the bell is the whole delivery mechanism.
 */
final class PortalNotifier
{
    /**
     * Ring one person's bell, if they are allowed to know.
     *
     * `$work` is the project or task the notification is ABOUT, and null when
     * the recipient is themselves the subject — their own leave, their own
     * fine. Null is not "skip the check": the portal check below still runs.
     *
     * Returns whether anything was written, so a caller that counts notices
     * counts the ones that actually happened.
     */
    public static function notify(?User $recipient, ?Model $work, Notification $notification): bool
    {
        if ($recipient === null || ! self::mayBeTold($recipient, $work)) {
            return false;
        }

        if ($notification instanceof CarriesItsSubject
            && self::alreadyTold($recipient, $notification)) {
            return false;
        }

        $recipient->notify($notification);

        return true;
    }

    /**
     * May this person be told about this piece of work?
     *
     * Two conditions, and both matter:
     *
     *   a) they are in the team portal at all — which keeps clients and every
     *      academy role out of all seven events in one place rather than in
     *      seven;
     *   b) they can see the work, through the existing scopes.
     *
     * A team member of the right team can still fail (b): a task is visible to
     * a member only while they hold or held a stint on it, which is narrower
     * than their team's board. That is the case the guard actually bites on —
     * mentioning a teammate in a task comment on work that was never theirs
     * tells them the task exists, and it is not theirs to know.
     */
    public static function mayBeTold(User $recipient, ?Model $work): bool
    {
        if (! TeamWorkVisibility::inTeamPortal($recipient)) {
            return false;
        }

        return $work === null || TeamWorkVisibility::seesOwner($recipient, $work);
    }

    /**
     * Has this person already been told this exact fact?
     *
     * Read off the STORED row rather than a timestamp window, which is what
     * makes a second run of the daily command silent and a re-run after a
     * failure safe. Narrowed by type and notifiable first — both indexed — so
     * the JSON comparison only ever runs over one person's rows of one class.
     *
     * The subject is compared through the query builder's JSON path (`data->`)
     * rather than a LIKE over the serialised text: the grammar emits the right
     * SQL for each driver, and a LIKE would be reading json_encode's output as
     * though it were a format.
     */
    public static function alreadyTold(User $recipient, CarriesItsSubject $notification): bool
    {
        return $recipient->notifications()
            ->where('type', $notification::class)
            ->where('data->subject', $notification->subject())
            ->exists();
    }
}
