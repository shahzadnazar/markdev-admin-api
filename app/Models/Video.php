<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Video extends Model
{
    protected $fillable = [
        'lesson_id',
        'provider',
        'url',
        'embed_url',
        'thumbnail_path',
        'duration_seconds',
        'captions_path',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
        ];
    }

    /* ------------------------------ Relations ------------------------------ */

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /* ------------------------------ Accessors ------------------------------ */

    /**
     * Self-hosted videos store a public-disk file path in `url`;
     * external providers store the canonical watch URL.
     */
    public function getUrlAttribute(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        /*
         * KNOWN GAP. Nothing in this app uploads a self-hosted video file — the
         * lesson form takes a watch URL or an embed URL — so this branch is
         * unreachable today. If self-hosted video is ever added, the file is
         * course material and belongs on the private disk behind an authorised
         * route; note that serving video needs HTTP range requests, which
         * Storage::download does not do, so it is a real piece of work rather
         * than another FileController one-liner. Left as it is rather than
         * half-built. The same applies to captions_path below.
         */
        if ($this->provider === 'self_hosted' && ! str_starts_with($value, 'http')) {
            return Storage::disk('public')->url($value);
        }

        return $value;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail_path ? Storage::disk('public')->url($this->thumbnail_path) : null;
    }

    /**
     * Best-effort iframe source: the stored embed URL when present,
     * otherwise derived from the watch URL for known providers.
     * Self-hosted videos play in a <video> tag instead — this returns null.
     */
    public function getEmbedSrcAttribute(): ?string
    {
        if ($this->embed_url) {
            return $this->embed_url;
        }

        $url = (string) $this->getRawOriginal('url');

        if ($this->provider === 'youtube' && $url !== '') {
            if (preg_match('~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:[^#]*&)?v=|shorts/|embed/|live/|v/)|youtu\.be/)([\w-]{6,})~i', $url, $m)) {
                return "https://www.youtube.com/embed/{$m[1]}";
            }
        }

        if ($this->provider === 'vimeo' && $url !== '' && preg_match('~vimeo\.com/(\d+)~', $url, $m)) {
            return "https://player.vimeo.com/video/{$m[1]}";
        }

        return null;
    }

    public function getCaptionsUrlAttribute(): ?string
    {
        return $this->captions_path ? Storage::disk('public')->url($this->captions_path) : null;
    }
}
