<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Somebody was mentioned in a staff comment.
 *
 * NOTHING DELIVERS ANYTHING YET. Notifications are the next phase; these rows
 * are written now because a mention that lives only inside the comment's text
 * is a mention that phase would have to re-parse — against a membership list
 * that may have changed since, and with no way to tell an intended mention from
 * an email address somebody pasted.
 *
 * Polymorphic because the three surfaces are three tables and a mention is the
 * same fact on all of them.
 */
class TeamCommentMention extends Model
{
    protected $fillable = ['comment_type', 'comment_id', 'user_id'];

    public function comment(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
