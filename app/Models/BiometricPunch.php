<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricPunch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'biometric_device_id',
        'biometric_id',
        'user_id',
        'punched_at',
        'direction',
        'status',
        'attendance_record_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
        ];
    }

    /* ------------------------------ Relations ------------------------------ */

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'biometric_device_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }
}
