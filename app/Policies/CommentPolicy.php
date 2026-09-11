<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

/**
 * A comment belongs to the person who wrote it.
 *
 * Editing or deleting someone else's is 403 from here, not a missing button.
 * The button is hidden too, but the button is decoration — this is the rule.
 *
 * Deliberately NOT giving admins an override. Gate::before already grants
 * super-admin everything, which is the one exception and an explicit one; no
 * other role gets to rewrite what a student said under that student's name.
 * Moderation, if it is ever wanted, is a delete with an audit trail, not a
 * silent edit, and it does not exist yet.
 */
class CommentPolicy
{
    public function update(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id;
    }

    public function delete(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id;
    }
}
