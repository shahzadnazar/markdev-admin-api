<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client asked something about their own project; the team-lead answers once.
 *
 * ## The answer is columns, not a thread
 *
 * One question, one answer. Not because a conversation would be hard, but
 * because a client-visible conversation is the thing phase 5 spent a whole table
 * design keeping out: "NO COMMENT IS EVER CLIENT-VISIBLE. There is no
 * per-comment toggle and there must not be one." A thread here would become
 * that toggle by another route. The lead talks it over in the ordinary project
 * discussion — which already exists, and which a client cannot see — and then
 * posts one answer.
 *
 * ## WHO MAY ANSWER
 *
 * The team-lead of the project's team, an admin, or a super-admin. NOT an
 * ordinary team member, and that is a rule about the relationship rather than
 * about capability: the client asked the lead, and a member replying directly is
 * the team talking to the client without the lead knowing. A member contributes
 * through the project discussion.
 *
 * Derived on every call from the project's current team, never stamped on the
 * row: a team's lead changes, and a stored answer-authority would keep naming
 * whoever held the job the day the question was asked.
 *
 * ## The client writes once
 *
 * There is no update and no destroy for a client. A question asked is asked —
 * editing it after an answer was drafted rewrites the thing being answered, and
 * deleting it removes a record of what a client was told. Both are the kind of
 * quiet edit this codebase refuses elsewhere (a settled attendance row, a
 * finished stint).
 */
class ClientQuestion extends Model
{
    use Auditable;

    /**
     * The states a question may be in, and how they read on screen.
     *
     * FIXED IN CODE, never a database enum — the same discipline as
     * TaskStatus::BEHAVIOURS and TaskAssignment::OUTCOMES, because the code is
     * what branches on them.
     *
     * - open      — asked, nobody has answered yet.
     * - answered  — one answer is posted and the client can read it.
     * - closed    — no answer is coming: asked twice, withdrawn, or dealt with
     *               on a call. Said plainly rather than left open for ever,
     *               because a question that sits "open" indefinitely is how a
     *               client concludes nobody read it.
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        'open' => 'Awaiting a reply',
        'answered' => 'Answered',
        'closed' => 'Closed without an answer',
    ];

    public const OPEN = 'open';

    public const ANSWERED = 'answered';

    public const CLOSED = 'closed';

    protected $attributes = ['status' => self::OPEN];

    protected $fillable = [
        'project_id',
        'asked_by',
        'body',
        'status',
        'answered_by',
        'answer_body',
        'answered_at',
    ];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime'];
    }

    /* ----------------------------- Relations ------------------------------ */

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asked_by');
    }

    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    /* ------------------------------- Scopes -------------------------------- */

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    /** Unanswered first, then newest: the lead's list is a queue. */
    public function scopeQueued(Builder $query): Builder
    {
        return $query
            ->orderByRaw("case when status = '".self::OPEN."' then 0 else 1 end")
            ->orderByDesc('id');
    }

    /* ------------------------------- Helpers ------------------------------- */

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isAnswered(): bool
    {
        return $this->status === self::ANSWERED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * May this person answer or close it?
     *
     * `clients.view` for the admin half, for the same reason milestones and the
     * file visibility flag use it: it is already the string that means "runs the
     * commercial side of client work", and a second permission would let the two
     * drift until one day the answer differed.
     *
     * The lead half is the team's CURRENT lead, read through the project. An
     * ordinary member of that team fails this and gets a 403 rather than a 404 —
     * unlike work outside your scope, this question is on a project they can
     * open and a page they are looking at, so there is nothing to hide and
     * pretending it does not exist would just be confusing.
     */
    public function mayBeAnsweredBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->can('clients.view')) {
            return true;
        }

        return (int) ($this->project?->team?->team_lead_id ?? 0) === (int) $user->getKey();
    }

    /**
     * Context on every audit row.
     *
     * An answer logs `answer_body`, `status` and the two stamps, which shows
     * WHAT was said and not what it was said about. The project's code and the
     * asker make the row readable on its own — and for a question, who asked is
     * exactly the fact a later audit is looking for.
     */
    public function auditContext(): array
    {
        return [
            'project_id' => $this->project_id,
            'project_code' => $this->project?->code,
            'asked_by' => $this->asked_by,
        ];
    }
}
