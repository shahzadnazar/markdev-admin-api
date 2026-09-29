<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Posting, editing and deleting a staff comment — once, for three surfaces.
 *
 * ## Who may change what
 *
 *   the author        their own, edit and delete
 *   super-admin       delete any
 *   anybody else      nothing, INCLUDING A TEAM-LEAD on their own team's posts
 *
 * A lead is not a moderator. They run the work; deciding whose words stay is a
 * different job, and handing it to the person whose delivery score the
 * conversation might be about is not a job anybody should be given.
 *
 * Every edit and delete goes through Auditable on the model, so it is recorded
 * wherever it comes from — a route added later, a console command, a fix run in
 * tinker — and not only from the endpoints that exist today.
 */
trait ManagesTeamComments
{
    /** @return array{body: string, parent_id: ?int} */
    protected function validatedComment(Request $request, string $table): array
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer', "exists:{$table},id"],
        ]);

        return [
            'body' => trim($data['body']),
            'parent_id' => ($data['parent_id'] ?? null) ? (int) $data['parent_id'] : null,
        ];
    }

    /** The author, and nobody else — not even to fix a typo for somebody. */
    protected function assertMayEdit(?User $user, Model $comment): void
    {
        abort_unless($user !== null && $user->getKey() === $comment->user_id, 403);
    }

    /**
     * The author, or a super-admin.
     *
     * Deliberately NOT `clients.view`, which is the admin gate used elsewhere:
     * removing somebody's words is a heavier act than reading a contract value,
     * and it stops with the one role that answers for the whole system.
     */
    protected function assertMayDelete(?User $user, Model $comment): void
    {
        abort_unless(
            $user !== null && ($user->getKey() === $comment->user_id || $user->hasRole('super-admin')),
            403,
        );
    }
}
