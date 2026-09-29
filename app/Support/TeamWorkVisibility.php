<?php

namespace App\Support;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * "May this person see this piece of work?" — asked through the EXISTING scopes.
 *
 * Every conversation and every file in the team portal hangs off a project or a
 * task, so its visibility is already decided: Project::scopeVisibleTo and
 * Task::scopeVisibleTo are the one row filter each. This class exists so the
 * comment controllers, the file routes and FileController all ask the same
 * question the same way instead of three of them re-deriving it — which is how
 * a screen added later quietly serves somebody else's work.
 *
 * Nothing here decides anything on its own. It delegates, and that is the
 * point.
 */
final class TeamWorkVisibility
{
    public static function seesProject(?User $user, ?Project $project): bool
    {
        if ($user === null || $project === null) {
            return false;
        }

        return Project::query()->visibleTo($user)->whereKey($project->getKey())->exists();
    }

    public static function seesTask(?User $user, ?Task $task): bool
    {
        if ($user === null || $task === null) {
            return false;
        }

        return Task::query()->visibleTo($user)->whereKey($task->getKey())->exists();
    }

    /**
     * The same question about whatever a file is attached to.
     *
     * An owner type nobody has taught this about is NOT visible. A new owner
     * type is a decision somebody makes here, not a default that lets the file
     * out while nobody is looking.
     */
    public static function seesOwner(?User $user, ?Model $owner): bool
    {
        return match (true) {
            $owner instanceof Project => self::seesProject($user, $owner),
            $owner instanceof Task => self::seesTask($user, $owner),
            default => false,
        };
    }

    /**
     * Whether this person is in the team portal at all.
     *
     * The cross-team channel is the one surface with no project or task behind
     * it, so it needs its own answer. Clients hold no team permission, and
     * neither do instructors, managers or students.
     */
    public static function inTeamPortal(?User $user): bool
    {
        return $user?->can('tasks.view') ?? false;
    }
}
