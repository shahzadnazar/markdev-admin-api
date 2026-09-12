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
        'name',
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

    /**
     * Where the panel or the portal fetches one of this student's documents.
     *
     * A route now, not a storage URL. These are CNIC scans and degree
     * certificates; served off the public disk they answered 200 to anyone who
     * knew the path, and the paths are guessable. FileController checks that
     * the asker is this student or staff who may view student records.
     *
     * $kind names which document rather than the path naming it, so a stored
     * path never has to round-trip through a URL where it could be swapped.
     */
    public function documentUrl(?string $kind): ?string
    {
        return $kind ? route('files.student-document', [$this, $kind]) : null;
    }

    /**
     * Browser path for a stored document, relative to whatever host is serving.
     *
     * Root-relative for the same reason it always was: route() builds on
     * APP_URL, and an admin panel opened on a different host or port than
     * APP_URL names would get URLs pointing at somewhere that serves nothing —
     * indistinguishable from a document that failed to upload. The panel is
     * always same-origin and arrives with a session cookie, so a relative path
     * is both correct and immune to that drift.
     */
    public function documentSrc(?string $kind): ?string
    {
        $url = $this->documentUrl($kind);

        return $url === null ? null : (parse_url($url, PHP_URL_PATH) ?: $url);
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
