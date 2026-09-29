<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\IsStatusPeriod;
use App\Models\Concerns\ScopesToDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How long a task sat on one status.
 *
 * Written as the transitions happen, because "was blocked between these dates"
 * is a question about the past that a status_id column cannot answer.
 *
 * `reason` is required when the status behaves as `blocked`. Parking a task in
 * Blocked stops that stint's clock, so it is a decision somebody has to justify
 * in writing, the same shape as declining a leave application. A member may
 * park their own task — they are the first to know they are stuck — and the
 * answer to the obvious worry is that parking is VISIBLE, counted and shown,
 * rather than forbidden.
 */
class TaskStatusPeriod extends Model
{
    use Auditable, IsStatusPeriod, ScopesToDay;

    protected $fillable = [
        'task_id',
        'task_status_id',
        'started_on',
        'ended_on',
        'reason',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'started_on' => 'date:Y-m-d',
            'ended_on' => 'date:Y-m-d',
        ];
    }

    public static function dayColumn(): string
    {
        return 'started_on';
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'task_status_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function auditContext(): array
    {
        return ['task_id' => $this->task_id];
    }
}
