<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class LessonResource extends Model
{
    /**
     * Exactly one owner: a lesson OR a course.
     *
     * This is the second invariant on this table, beside file-or-link, and the
     * two are independent — a course-level link and a lesson-level file are
     * both valid. Enforced here rather than by a CHECK constraint because the
     * syntax differs across the drivers this runs on, and a model guard
     * behaves the same on all of them. A row with both owners, or neither, is
     * a bug the moment it is written, so it throws rather than being saved and
     * found later.
     */
    // booted(), not bootLessonResource(): the boot{Name} convention is for
    // TRAITS. A method named after the model is simply never called, which is
    // how this guard silently did nothing on its first outing.
    protected static function booted(): void
    {
        static::saving(function (self $resource) {
            $owners = (int) ($resource->lesson_id !== null) + (int) ($resource->course_id !== null);

            if ($owners !== 1) {
                throw new \InvalidArgumentException(
                    'A resource belongs to exactly one of a lesson or a course, not '
                    .($owners === 0 ? 'neither' : 'both').'.',
                );
            }
        });
    }

    public const KIND_FILE = 'file';

    public const KIND_LINK = 'link';

    protected $fillable = [
        'lesson_id',
        'course_id',
        'name',
        'kind',
        'url',
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

    /* ------------------------------ Relations ------------------------------ */

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /* ------------------------------- Scopes -------------------------------- */

    /** Resources attached to the course itself, not to one of its lessons. */
    public function scopeCourseLevel(Builder $query): Builder
    {
        return $query->whereNull('lesson_id')->whereNotNull('course_id');
    }

    /**
     * The course this resource belongs to, at either level.
     *
     * A lesson-level row carries course_id null and reaches its course through
     * the lesson; a course-level row carries it directly. One accessor so a
     * caller never has to know which.
     */
    public function getOwningCourseIdAttribute(): ?int
    {
        return $this->course_id ?? $this->lesson?->course_id ?? $this->lesson()->value('course_id');
    }

    /* ------------------------------ Accessors ------------------------------ */

    public function getFileUrlAttribute(): ?string
    {
        return $this->file_path ? route('files.resource', $this) : null;
    }

    public function isLink(): bool
    {
        return $this->kind === self::KIND_LINK;
    }

    /**
     * Where this resource points, whichever kind it is.
     *
     * One accessor so a caller never has to ask which column to read.
     */
    public function getTargetUrlAttribute(): ?string
    {
        return $this->isLink() ? $this->url : $this->file_url;
    }

    /**
     * Whether a link is a YouTube one, for an icon and a label.
     *
     * Derived, not stored: it is already in the url, and a second copy is a
     * second thing to keep true. Matches the host exactly rather than with a
     * substring, so `youtube.com.example.com` is not treated as YouTube.
     */
    public function getIsYoutubeAttribute(): bool
    {
        if (! $this->isLink() || ! $this->url) {
            return false;
        }

        $host = strtolower((string) parse_url($this->url, PHP_URL_HOST));
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return in_array($host, ['youtube.com', 'm.youtube.com', 'youtu.be'], true);
    }
}
