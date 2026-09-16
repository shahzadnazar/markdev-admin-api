<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class AssignmentSubmission extends Model
{

    /**
     * Grading moves this student's assignment score for the course.
     *
     * Fires on every save, not only when graded_at appears: ungrading, a
     * regrade, or a soft delete all change the average too, and the scorer
     * decides what an ungraded submission is worth rather than this hook.
     */
    protected static function booted(): void
    {
        static::saved(fn (self $submission) => $submission->refreshCourseProgress());
        static::deleted(fn (self $submission) => $submission->refreshCourseProgress());
    }

    protected function refreshCourseProgress(): void
    {
        $course = $this->assignment?->course;

        if ($course !== null && $this->user !== null) {
            app(\App\Services\ProgressCache::class)->refresh($this->user, $course);
        }
    }
    use Auditable, SoftDeletes;

    protected $fillable = [
        'assignment_id',
        'user_id',
        'content',
        'query',
        'file_path',
        'file_name',
        'submitted_at',
        'is_late',
        'score',
        'feedback',
        'graded_at',
        'graded_by',
        'returned_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'is_late' => 'boolean',
            'score' => 'integer',
            'graded_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    /* ------------------------------ Relations ------------------------------ */

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    /* ------------------------------ Accessors ------------------------------ */

    public function getFileUrlAttribute(): ?string
    {
        return $this->file_path ? route('files.submission', $this) : null;
    }
}
