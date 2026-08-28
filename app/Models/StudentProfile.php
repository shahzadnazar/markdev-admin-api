<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Admission record captured by the MarkDev registration form. */
class StudentProfile extends Model
{
    use Auditable;

    protected $fillable = [
        'user_id',
        'reg_no',
        'father_name',
        'date_of_birth',
        'gender',
        'address',
        'cnic',
        'batch_no',
        'attendance_slot_id',
        'guardian_contact',
        'current_qualification',
        'applied_course',
        'emergency_name',
        'emergency_contact',
        'emergency_relation',
        'emergency_residence',
        'date_of_joining',
        'reference',
        'total_fee',
        'submitted_fee',
        'registration_fee',
        'photo_path',
        'cnic_doc_path',
        'degree_doc_path',
        'terms_accepted_at',
        'registered_by',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'date_of_joining' => 'date',
            'total_fee' => 'decimal:2',
            'submitted_fee' => 'decimal:2',
            'registration_fee' => 'decimal:2',
            'terms_accepted_at' => 'datetime',
        ];
    }

    /* ------------------------------ Relations ------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * The daily slot this student attends, which decides when they are late.
     *
     * Left null for students admitted before slots existed; they fall back to
     * the academy-wide day start.
     */
    public function attendanceSlot(): BelongsTo
    {
        return $this->belongsTo(AttendanceSlot::class);
    }

    /* ------------------------------ Documents ------------------------------ */

    public function documentUrl(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    public static function isImagePath(?string $path): bool
    {
        return $path !== null && in_array(
            strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            ['jpg', 'jpeg', 'png', 'webp'],
            true,
        );
    }

    /** Next sequential registration number, e.g. MD-2026-0007. */
    public static function nextRegNo(): string
    {
        $year = now()->year;
        $prefix = "MD-{$year}-";

        $last = static::where('reg_no', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('reg_no');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
