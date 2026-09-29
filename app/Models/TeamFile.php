<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Number;

/**
 * A file attached to a project or a task.
 *
 * ## Always private
 *
 * The bytes live on PrivateFiles::DISK under `team-files/`, outside the
 * document root, and are reached only through FileController. A student's
 * photograph was once served to anyone who guessed the URL; nothing here
 * repeats that. A signed link proves WHO is asking and never that they may
 * look — the controller still asks whether this viewer can see the owning
 * project or task.
 *
 * ## The soft delete keeps the bytes
 *
 * Deleting a row does not delete the file. A restored row whose bytes are gone
 * is worse than one that takes disk space: the first is a broken record
 * somebody has to explain, the second is a line item on a bill.
 */
class TeamFile extends Model
{
    use Auditable, SoftDeletes;

    /** Where every team file is stored, and a PrivateFiles::PRIVATE_PREFIXES entry. */
    public const PREFIX = 'team-files';

    /**
     * The validator's own ceiling, in kilobytes, per kind.
     *
     * UploadLimits takes the smaller of these and what php.ini will really
     * accept at render time, so a chip cannot promise more than the server can
     * keep. The numbers live here once; nothing restates them.
     */
    public const IMAGE_MAX_KB = 1024;

    public const FILE_MAX_KB = 5120;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'path',
        'original_name',
        'mime',
        'size_bytes',
        'uploaded_by',
        'is_client_visible',
    ];

    protected function casts(): array
    {
        return [
            'is_client_visible' => 'boolean',
            'size_bytes' => 'integer',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    /** "1.2 MB" — the same wording the upload chip uses. */
    public function sizeLabel(): string
    {
        return Number::fileSize($this->size_bytes, precision: $this->size_bytes >= 1024 * 1024 ? 1 : 0);
    }

    /** Whether this person may remove it: the uploader, or an admin. */
    public function mayBeDeletedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->getKey() === $this->uploaded_by || $user->can('clients.view');
    }

    protected function extraAuditContext(): array
    {
        return [];
    }

    public function auditContext(): array
    {
        return [
            'owner_type' => $this->owner_type,
            'owner_id' => $this->owner_id,
            'original_name' => $this->original_name,
        ];
    }
}
