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

        if ($this->provider === 'self_hosted' && ! str_starts_with($value, 'http')) {
            return Storage::disk('public')->url($value);
        }

        return $value;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->thumbnail_path ? Storage::disk('public')->url($this->thumbnail_path) : null;
    }

    public function getCaptionsUrlAttribute(): ?string
    {
        return $this->captions_path ? Storage::disk('public')->url($this->captions_path) : null;
    }
}
