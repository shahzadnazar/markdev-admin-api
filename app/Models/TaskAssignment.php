<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopesToDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A STINT: one person, one task, one period of responsibility.
 *
 * ## The score is computed over these, never over tasks
 *
 * A task can change hands, be reopened, and be worked by three people in turn.
 * Scoring the task would mean picking one of them to carry all of it. A stint
 * is a promise one person made for a stated number of days, which is the only
 * thing it is fair to judge them on.
 *
 * ## Why there is no lock on `done`
 *
 * The register has an absent lock, because a corrected attendance row
 * OVERWRITES what it used to say. Nothing here overwrites: reopening a task
 * closes nothing retroactively. The first stint keeps its real `ended_on` and
 * its real outcome, and a second stint opens beside it. THE HISTORY IS THE
 * LOCK — a late delivery that was reopened and fixed still reads as one late
 * stint and one more stint, which is what happened.
 *
 * ## Handover
 *
 * Reassigning closes the open stint as `handed_over` and opens a new one whose
 * allowance the lead types. `handed_over` is in NEITHER half of the score: the
 * leaver did not finish, so they cannot be marked on time, and they were not
 * given a chance to be late either. Its days stay visible on the task, because
 * the work happened and somebody should be able to see where it went.
 *
 * The remaining days are never carried over silently. A stint opened with
 * whatever was left over would doom whoever picked it up for a delay that was
 * not theirs; somebody has to type a new promise and be accountable for it.
 */
class TaskAssignment extends Model
{
    use Auditable, ScopesToDay;

    /**
     * The outcomes a stint may have, and how they read on screen.
     *
     * FIXED IN CODE, never a database enum — the same discipline as
     * TaskStatus::BEHAVIOURS, because the code is what branches on them.
     *
     * - in_progress  — open. No outcome yet; not counted anywhere.
     * - early        — finished inside its allowance with days to spare.
     * - on_time      — finished within its allowance.
     * - late         — finished over its allowance.
     * - handed_over  — reassigned before finishing. Counts as neither on time
     *                  nor late, and its days are still shown.
     *
     * @var array<string, string>
     */
    public const OUTCOMES = [
        'in_progress' => 'In progress',
        'early' => 'Early',
        'on_time' => 'On time',
        'late' => 'Late',
        'handed_over' => 'Handed over',
    ];

    /** Outcomes that count in the numerator — delivered on time or better. */
    public const KEPT_THE_PROMISE = ['early', 'on_time'];

    /** Outcomes that count in the denominator: every FINISHED stint. */
    public const FINISHED = ['early', 'on_time', 'late'];

    protected $fillable = [
        'task_id',
        'user_id',
        'days_allowed',
        'started_on',
        'ended_on',
        'outcome',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'started_on' => 'date:Y-m-d',
            'ended_on' => 'date:Y-m-d',
            'days_allowed' => 'integer',
        ];
    }

    public static function dayColumn(): string
    {
        return 'started_on';
    }

    /* ----------------------------- Relations ------------------------------ */

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------- Scopes -------------------------------- */

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_on');
    }

    /** Every stint that reached an end somebody can be judged on. */
    public function scopeFinished(Builder $query): Builder
    {
        return $query->whereIn('outcome', static::FINISHED);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /* ------------------------------- Helpers ------------------------------- */

    public function isOpen(): bool
    {
        return $this->ended_on === null;
    }

    public function outcomeLabel(): string
    {
        return static::OUTCOMES[$this->outcome ?? 'in_progress'] ?? (string) $this->outcome;
    }

    public function auditContext(): array
    {
        return [
            'task_id' => $this->task_id,
            'user_id' => $this->user_id,
            'days_allowed' => $this->days_allowed,
        ];
    }
}
