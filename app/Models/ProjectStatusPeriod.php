<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\IsStatusPeriod;
use App\Models\Concerns\ScopesToDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How long a project sat on one status.
 *
 * The twin of TaskStatusPeriod, and for the same reason: a stint's days taken
 * exclude the days its project was PAUSED, and "was paused" needs the
 * transitions recorded as they happened.
 */
class ProjectStatusPeriod extends Model
{
    use Auditable, IsStatusPeriod, ScopesToDay;

    protected $fillable = [
        'project_id',
        'project_status_id',
        'started_on',
        'ended_on',
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'project_status_id');
    }
}
