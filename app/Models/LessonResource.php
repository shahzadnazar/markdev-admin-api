<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class LessonResource extends Model
{
    public const KIND_FILE = 'file';

    public const KIND_LINK = 'link';

    protected $fillable = [
        'lesson_id',
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

    /* ------------------------------ Accessors ------------------------------ */

    public function getFileUrlAttribute(): ?string
    {
        return $this->file_path ? Storage::disk('public')->url($this->file_path) : null;
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
