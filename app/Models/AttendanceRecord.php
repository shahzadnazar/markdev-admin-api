<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\LocksAbsences;
use App\Models\Concerns\ScopesToDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per student per class session — the sheet an instructor marks from
 * Learning → Attendance.
 *
 * Separate from DailyAttendance, which is the day-per-student register that
 * fines and attendance percentages read. Nothing links the two, and this one
 * feeds no charge. It shares the absent lock all the same: a screen that says
 * a student was absent and a screen that says they were present cannot both
 * be right, and an instructor who is refused on one should not find the other
 * open.
 */
class AttendanceRecord extends Model
{
    use Auditable, LocksAbsences, ScopesToDay;

    /** What an instructor may choose on the sheet. */
    public const STATUSES = ['present', 'late', 'absent', 'excused'];

    protected $fillable = [
        'user_id',
        'course_id',
        'session_title',
        'date',
        'status',
        'notes',
        'recorded_by',
        'source',
        'biometric_device_id',
        'last_updated_by',
        'last_update_reason',
        'last_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'last_updated_at' => 'datetime',
        ];
    }

    /* ------------------------------ Relations ------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_updated_by');
    }
}
