<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ClientQuestion;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\TeamFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * "Which client is this, and what may they see?" — asked in ONE place.
 *
 * The client half of what TeamWorkVisibility is for the team half, and written
 * the same way for the same reason: every screen, every file route and every
 * write asks the same question through the same function, so a screen added
 * later cannot re-derive it slightly differently.
 *
 * ## THE SCOPE IS THE SESSION, NEVER A PARAMETER
 *
 * Every read starts from `clients.user_id === the signed-in user`. Not from a
 * `?client=` , not from a path segment, not from a hidden field — there is no
 * code path in the client portal where a client id arrives from the request at
 * all. That is the single decision the whole portal rests on: a client portal
 * that takes its subject from the URL is one guessed integer away from being
 * every client's portal.
 *
 * A project that is not theirs is a 404, not a 403. A refusal confirms the
 * project exists, and for client work the existence of somebody else's
 * engagement is already a fact worth protecting — the same rule the team
 * portal's screens follow.
 *
 * ## What a client may see of a project
 *
 * The project's name, code, status and dates; the milestones FLAGGED
 * `is_client_visible`; the files FLAGGED `is_client_visible`; their own
 * questions and the answers.
 *
 * Not the team, not a member, not a task, not a comment, not a stint, not a
 * score, not the contract value and not the currency. Those are not "hidden by
 * the view" — the queries here do not select them and do not load the relations
 * that carry them, so a template that reached for one would find nothing.
 */
final class ClientPortal
{
    /**
     * The client record behind this login, or null.
     *
     * Deliberately NOT filtered by the client's `is_active`. Deactivating a
     * client means "stop offering them on new projects" — phase 2's toggle says
     * so in as many words, and their existing projects stay readable. The switch
     * that means "this person may not sign in" is the USER's own `is_active`,
     * which the login already honours, and it is the one switch that means that
     * everywhere.
     */
    public static function clientFor(?User $user): ?Client
    {
        if ($user === null) {
            return null;
        }

        return Client::query()->where('user_id', $user->getKey())->first();
    }

    /** Whether this login is a client at all — the client group's whole door. */
    public static function isClient(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return Client::query()->where('user_id', $user->getKey())->exists();
    }

    /**
     * The projects this client may see — the ONE row filter.
     *
     * Returns a builder rather than a collection so a caller can order, count or
     * eager-load without being tempted to start its own query. A login with no
     * client record matches nothing, stated rather than left to an empty `where
     * client_id = null` that would quietly match rows if the column were ever
     * nullable.
     */
    public static function projects(?User $user): Builder
    {
        $client = self::clientFor($user);

        if ($client === null) {
            return Project::query()->whereRaw('1 = 0');
        }

        return Project::query()->where('client_id', $client->getKey());
    }

    /** Whether this client may see this project. Used before every write. */
    public static function seesProject(?User $user, ?Project $project): bool
    {
        if ($project === null) {
            return false;
        }

        return self::projects($user)->whereKey($project->getKey())->exists();
    }

    /**
     * Whether this client may read this file.
     *
     * TWO conditions, and both are load-bearing:
     *
     *   a) the file is flagged `is_client_visible` — a flag only an admin can
     *      set, which is the whole mechanism by which anything reaches a client
     *      at all;
     *   b) the thing it hangs off is a PROJECT of theirs.
     *
     * (b) alone would serve every internal file on their project. (a) alone
     * would serve another client's shared file to whoever asked. The owner must
     * be a Project and nothing else: a TASK file is never a client's business,
     * even a flagged one, because tasks are not something a client is shown —
     * so an owner type that is not a Project is refused rather than inspected.
     */
    public static function seesFile(?User $user, ?TeamFile $file): bool
    {
        if ($file === null || ! $file->is_client_visible) {
            return false;
        }

        $owner = $file->owner;

        return $owner instanceof Project && self::seesProject($user, $owner);
    }

    /**
     * The milestones a client may see on a project, in the admin's order.
     *
     * Their due date and whether they are complete, which is how a client learns
     * progress. Nothing about who did it or how long it took: no team, no
     * member, no task, no stint.
     */
    public static function milestones(Project $project): Collection
    {
        return ProjectMilestone::query()
            ->where('project_id', $project->getKey())
            ->where('is_client_visible', true)
            ->ordered()
            ->get(['id', 'project_id', 'name', 'due_date', 'completed_on']);
    }

    /**
     * The files a client may see on a project.
     *
     * The same flag seesFile checks, applied as a row filter so an unflagged
     * file is not merely unrendered but absent. `owner` is matched on the morph
     * alias rather than the class name, so this keeps working if the map is ever
     * given one.
     */
    public static function files(Project $project): Collection
    {
        return TeamFile::query()
            ->where('owner_type', self::morphAliasFor(Project::class))
            ->where('owner_id', $project->getKey())
            ->where('is_client_visible', true)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * This client's questions on a project, unanswered first.
     *
     * Only the fields a client is shown: the question, the answer, when it was
     * answered. NOT `answered_by` — a client does not need the name of the
     * person who typed it, and "the team answered" is the honest description of
     * what happened, since the lead writes it after talking to the team.
     */
    public static function questions(Project $project): Collection
    {
        return ClientQuestion::query()
            ->where('project_id', $project->getKey())
            ->queued()
            ->get(['id', 'project_id', 'body', 'status', 'answer_body', 'answered_at', 'created_at']);
    }

    /** The morph alias for a class, or the class name when it has none. */
    private static function morphAliasFor(string $class): string
    {
        return array_search($class, Relation::morphMap() ?: [], true) ?: $class;
    }
}
