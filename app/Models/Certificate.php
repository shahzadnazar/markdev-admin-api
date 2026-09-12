<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Certificate extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'user_id',
        'course_id',
        'certificate_number',
        'issued_at',
        'file_path',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
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

    /* ------------------------------ Accessors ------------------------------ */

    public function getDownloadUrlAttribute(): ?string
    {
        /*
         * Always null, and deliberately so.
         *
         * Nothing writes invoices.file_path / certificates.file_path — the
         * column and this accessor predate the signed download routes that
         * actually serve these documents (api.v1.billing.invoices.receipt and
         * api.v1.certificates.download). It used to mint a public-disk URL,
         * which meant the day someone did start writing a PDF here, it would
         * have been world-readable the moment it landed.
         *
         * If a stored PDF is wanted, add a FileController method and an
         * authorisation rule for it; do not restore a storage URL.
         */
        return null;
    }
}
