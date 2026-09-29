<?php

namespace App\Models\Concerns;

use App\Models\TeamCommentMention;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * What the three staff conversations have in common.
 *
 * Extracted rather than written out three times: one level of replies, the
 * author relations, the audit context, and writing the @mention rows. The only
 * thing each surface says for itself is WHO may be mentioned there, which is
 * the one real difference between them.
 *
 * ## NO COMMENT IS EVER CLIENT-VISIBLE
 *
 * There is no per-comment visibility flag on any of these tables and there must
 * not be one. A toggle is how somebody shows a client the wrong thing at 11pm:
 * the safe state has to be the only state, not the default. Anything a client
 * is meant to read is written deliberately, somewhere else, in the phase that
 * builds their portal. A later phase reaching for "just a bit of flexibility"
 * here should change that phase instead.
 *
 * ## One level of replies
 *
 * A reply may not be replied to, the same rule as task splitting and for the
 * same reason: two levels is a screen anyone can read and recursion is a screen
 * nobody can. Enforced on `saving`, because a form is not the only way a row
 * gets written.
 */
trait IsTeamComment
{
    /**
     * Users this surface allows to be mentioned.
     *
     * A mention of anybody else is stored as plain text and no row is written.
     * Not an error: people type @ for other reasons, and refusing a comment
     * over it would be a worse answer than quietly not linking it.
     *
     * @return Collection<int, User>
     */
    abstract public function mentionableUsers();

    public static function bootIsTeamComment(): void
    {
        static::saving(function (self $comment): void {
            $comment->assertOneLevelDeep();
        });

        // After the row exists, so the mention has something to point at. On an
        // edit the set is rewritten, because a mention removed from the text is
        // a mention that is no longer there.
        static::saved(function (self $comment): void {
            $comment->syncMentions();
        });
    }

    /* ------------------------------- Threading ------------------------------ */

    protected function assertOneLevelDeep(): void
    {
        if ($this->parent_id === null) {
            return;
        }

        $parent = static::withTrashed()->find($this->parent_id);

        if ($parent?->parent_id !== null) {
            throw ValidationException::withMessages([
                'parent_id' => 'A reply cannot be replied to. Reply to the message that started the thread instead.',
            ]);
        }

        if ($this->exists && $this->replies()->exists()) {
            throw ValidationException::withMessages([
                'parent_id' => 'This message already has replies, so it cannot become a reply itself.',
            ]);
        }
    }

    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }

    /* ------------------------------- Relations ------------------------------ */

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    /**
     * The replies under this opener.
     *
     * Deliberately NOT filtered by the parent's own deleted_at: a deleted
     * opener leaves its replies readable, because a thread is other people's
     * words and removing one of them must not take the rest.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id')->orderBy('id');
    }

    public function mentions(): MorphMany
    {
        return $this->morphMany(TeamCommentMention::class, 'comment');
    }

    /* -------------------------------- Scopes -------------------------------- */

    /** Thread openers, newest last, the way a conversation reads. */
    public function scopeThreads(Builder $query): Builder
    {
        return $query->whereNull('parent_id')->orderBy('id');
    }

    /* -------------------------------- Mentions ------------------------------ */

    /**
     * The handles this body mentions, lower-cased and unique.
     *
     * A handle is `@` followed by the slugged form of somebody's name —
     * `@ayesha.khan` — which is predictable enough to type and unambiguous
     * enough to resolve, unlike a bare first name.
     *
     * @return array<int, string>
     */
    public static function handlesIn(?string $body): array
    {
        preg_match_all('/@([a-z0-9][a-z0-9._-]*)/i', (string) $body, $matches);

        return array_values(array_unique(array_map('mb_strtolower', $matches[1] ?? [])));
    }

    /**
     * Write the mention rows for this comment.
     *
     * Only for users this surface allows: a project discussion reaches that
     * project's team, a task comment reaches the task's team, the channel
     * reaches everybody in the portal. Anything else stays plain text.
     *
     * Nothing is delivered — notifications are the next phase. These rows exist
     * so that phase has a fact to read rather than a string to re-parse.
     */
    public function syncMentions(): void
    {
        $handles = static::handlesIn($this->body);

        $ids = $handles === []
            ? []
            : $this->mentionableUsers()
                ->filter(fn (User $user) => in_array($user->mentionHandle(), $handles, true))
                // Mentioning yourself is a typing habit, not a notification.
                ->reject(fn (User $user) => $user->getKey() === $this->user_id)
                ->pluck('id')
                ->all();

        $this->mentions()->whereNotIn('user_id', $ids ?: [0])->delete();

        foreach ($ids as $id) {
            TeamCommentMention::firstOrCreate([
                'comment_type' => $this->getMorphClass(),
                'comment_id' => $this->getKey(),
                'user_id' => $id,
            ]);
        }
    }

    /* --------------------------------- Audit -------------------------------- */

    /**
     * Who wrote it and whether the actor is that person.
     *
     * An update logs only the changed columns, which here is `body` alone —
     * that shows what it said and now says, but not whose it was. The audit row
     * already names the ACTOR; this names the SUBJECT, so "super-admin deleted
     * Bilal's message" and "Bilal deleted his own" are two visibly different
     * rows rather than the same row read twice. The same shape the student
     * comments use.
     *
     * @return array<string, mixed>
     */
    public function auditContext(): array
    {
        $actorId = Auth::id();

        return [
            'author_id' => $this->user_id,
            'author_name' => $this->author?->name ?? $this->author()->value('name'),
            // Null when nobody is signed in, because false would claim somebody
            // acted on another's behalf.
            'by_owner' => $actorId === null ? null : $actorId === $this->user_id,
            'surface' => Str::of(class_basename($this))->headline()->toString(),
        ] + $this->extraAuditContext();
    }

    /**
     * Anything this surface adds to the audit row.
     *
     * A hook rather than an override, so a host can add its own without having
     * to alias the trait method to call it — which is the kind of ceremony
     * somebody skips, leaving the shared half out of the row entirely.
     *
     * @return array<string, mixed>
     */
    protected function extraAuditContext(): array
    {
        return [];
    }
}
