<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\IsWorkflowStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * The state of a client project: the admin's wording plus the behaviour code
 * branches on.
 *
 * The two closed behaviours are separate on purpose. "Completed" and
 * "Cancelled" both end a project, and every report that counts delivery has to
 * tell them apart — a single `closed` with the difference living in the label
 * would make that impossible the moment someone renamed one.
 */
class ProjectStatus extends Model
{
    use Auditable, IsWorkflowStatus;

    /**
     * The behaviours a project status may have, and how they read on screen.
     *
     * FIXED IN CODE, for the same reason as TaskStatus::BEHAVIOURS.
     *
     * - planning          — agreed but not started.
     * - running           — work in progress.
     * - paused            — on hold. Its days do not count against the estimate.
     * - closed_success    — delivered. Counts as a completed project.
     * - closed_abandoned  — ended without delivering. Never counts as one.
     *
     * @var array<string, string>
     */
    public const BEHAVIOURS = [
        'planning' => 'Planning — agreed but not started',
        'running' => 'Running — work in progress',
        'paused' => 'Paused — on hold',
        'closed_success' => 'Closed — delivered',
        'closed_abandoned' => 'Closed — abandoned',
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
     * How many projects currently sit on this status.
     *
     * Zero until the phase that builds projects; see TaskStatus::usageCount
     * for why the seam is here rather than added later.
     */
    public function usageCount(): int
    {
        return 0;
    }
}
