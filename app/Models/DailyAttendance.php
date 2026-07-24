<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attendance row per active student per day — marked once, then only
 * correctable through the PIN-gated update flow with a written reason.
 */
class DailyAttendance extends Model
{
    use Auditable;

    public const STATUSES = ['present', 'late', 'absent', 'leave'];

    protected $table = 'daily_attendance_records';

    protected $fillable = [
        'user_id',
        'date',
        'status',
        'remarks',
        'source',
        'marked_by',
        'marked_at',
        'last_updated_by',
        'last_update_reason',
        'last_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'marked_at' => 'datetime',
            'last_updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_updated_by');
    }
}
