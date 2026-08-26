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
            // `embed_src`, not the raw `embed_url` column: that column is optional in
            // the lesson form, so a lesson saved with only a watch URL sent null here
            // and the portal fell back to the watch URL — which YouTube and Vimeo
            // refuse to render in an iframe. The accessor derives the embed URL and
            // still prefers the stored column when it is set.
            'embed_url' => $this->embed_src,
            'thumbnail_url' => $this->thumbnail_url,
            'duration_seconds' => (int) $this->duration_seconds,
            'captions_url' => $this->captions_url,
        ];
    }
}
