<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\IsWorkflowStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * A column on the task board: the admin's wording plus the behaviour code
 * branches on.
 *
 * "Waiting on client" is a perfectly good status for an academy to add, and it
 * behaves as `blocked`. "Review" and "In Progress" are two statuses that both
 * behave as `active`, which is normal — several labels per behaviour is the
 * point, and is why the behaviour is not simply the label lower-cased.
 */
class TaskStatus extends Model
{
    use Auditable, IsWorkflowStatus;

    /**
     * The behaviours a task status may have, and how they read on screen.
     *
     * FIXED IN CODE. An admin picks one; nobody invents one. Adding a behaviour
     * means writing the code that branches on it, so it is a deploy, not a
     * form submission.
     *
     * - open    — not started. Not counted as work in progress.
     * - active  — being worked on. Counts towards elapsed effort.
     * - blocked — waiting on someone else. Its days are EXCLUDED from variance,
     *             because a team cannot be judged on time it was not given.
     * - done    — finished. Records the actual days the task took.
     *
     * @var array<string, string>
     */
    public const BEHAVIOURS = [
        'open' => 'Open — not started yet',
        'active' => 'Active — being worked on',
        'blocked' => 'Blocked — waiting on someone else',
        'done' => 'Done — finished',
    ];

    protected $fillable = [
        'label',
        'behaviour',
        'colour',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * How many tasks currently sit on this status.
     *
     * Zero until the phase that builds tasks: there is no `tasks` table yet, so
     * nothing can be pointing here. The delete path already refuses a non-zero
     * answer, so that phase adds the count and inherits the refusal rather than
     * having to remember it — which is the half of this that is easy to forget.
     */
    public function usageCount(): int
    {
        return 0;
    }
}
