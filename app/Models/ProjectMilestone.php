<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopesToDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A checkpoint on the way to delivering a project.
 *
 * Belongs to the PROJECT and to nothing else — the project already says which
 * team is doing the work, and a milestone carrying its own team would be a
 * second answer to keep in step with the first.
 *
 * `is_client_visible` is written but never rendered in this phase; the client
 * portal is phase 6. It defaults to false, because the safe reading of a
 * milestone nobody marked is "not shared".
 */
class ProjectMilestone extends Model
{
    use Auditable, ScopesToDay;

    protected $fillable = [
        'project_id',
        'name',
        'due_date',
        'completed_on',
        'sort_order',
        'is_client_visible',
    ];

    protected function casts(): array
    {
        return [
            // Queried only through ScopesToDay, like every other date column
            // in this codebase. See the trait for what equality costs here.
            'due_date' => 'date:Y-m-d',
            'completed_on' => 'date:Y-m-d',
            'sort_order' => 'integer',
            'is_client_visible' => 'boolean',
        ];
    }

    public static function dayColumn(): string
    {
        return 'due_date';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Done is a date, not a flag: "when" answers "whether" and adds the day. */
    public function isComplete(): bool
    {
        return $this->completed_on !== null;
    }

    public function scopeComplete(Builder $query): Builder
    {
        return $query->whereNotNull('completed_on');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('completed_on');
    }

    /** The order the admin arranged them in. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Context on every audit row for a milestone.
     *
     * An update logs only what changed, which for a completion is a bare date
     * — enough to see WHAT changed and not enough to see which project it was
     * on. The project's id and code make the row readable on its own.
     */
    public function auditContext(): array
    {
        return [
            'project_id' => $this->project_id,
            'project_code' => $this->project?->code,
        ];
    }
}
