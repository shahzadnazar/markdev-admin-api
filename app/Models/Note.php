<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Note extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'course_id',
        'instructor_id',
        'title',
        'description',
        'file_path',
        'file_type',
        'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function getFileUrlAttribute(): ?string
    {
        // A route, not a storage URL: note files are course material for
        // enrolled students, and off the public disk they answered to anyone
        // with the path. FileController checks enrollment.
        return $this->file_path ? route('files.note', $this) : null;
    }
}