<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BiometricDevice extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'name',
        'vendor',
        'serial_number',
        'location',
        'course_id',
        'api_key',
        'session_start',
        'late_after_minutes',
        'is_active',
        'last_seen_at',
    ];

    /** Keys never leak into audit snapshots or API responses. */
    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'late_after_minutes' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    public static function generateKey(): string
    {
        return 'mdk_'.Str::random(40);
    }

    /* ------------------------------ Relations ------------------------------ */

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function punches(): HasMany
    {
        return $this->hasMany(BiometricPunch::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
