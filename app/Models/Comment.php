<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * A comment on a lesson.
 *
 * Audited through the shared Auditable concern rather than by logging from the
 * controller, so an edit is recorded wherever it comes from — a route added
 * later, a console command, a fix run in tinker — and not only from the two
 * endpoints that exist today.
 */
class Comment extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'lesson_id',
        'user_id',
        'parent_id',
        'body',
    ];

    /* ------------------------------ Relations ------------------------------ */

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id');
    }

    /* -------------------------------- Audit -------------------------------- */

    /**
     * Who wrote it, where, and whether the actor is that person.
     *
     * An update logs only the changed columns, which here is `body` alone —
     * that shows what the comment said and now says, but not whose it was. The
     * audit row already names the ACTOR and their role; this names the SUBJECT,
     * so "super-admin edited Ali's comment" and "Ali edited his own comment"
     * are two visibly different rows rather than the same row read twice.
     *
     * `by_owner` is computed at event time from the authenticated user, which
     * is the only moment it is knowable — the model cannot work it out later.
     *
     * @return array<string, mixed>
     */
    public function auditContext(): array
    {
        $actorId = Auth::id();

        return [
            'lesson_id' => $this->lesson_id,
            'author_id' => $this->user_id,
            'author_name' => $this->user?->name ?? $this->user()->value('name'),
            // Null when nobody is signed in (a seeder, a queued job), because
            // false would claim someone acted on another's behalf.
            'by_owner' => $actorId === null ? null : $actorId === $this->user_id,
        ];
    }
}
