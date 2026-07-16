<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Video */
class VideoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lesson_id' => $this->lesson_id,
            'provider' => $this->provider,
            'url' => $this->url,
            'embed_url' => $this->embed_url,
            'thumbnail_url' => $this->thumbnail_url,
            'duration_seconds' => (int) $this->duration_seconds,
            'captions_url' => $this->captions_url,
        ];
    }
}
