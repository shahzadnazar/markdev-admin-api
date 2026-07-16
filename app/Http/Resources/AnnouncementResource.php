<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Announcement */
class AnnouncementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'author' => [
                'id' => $this->author?->id,
                'name' => $this->author?->name,
                'avatar_url' => $this->author?->avatar_url,
            ],
            'course' => $this->course ? new CourseRefResource($this->course) : null,
            'is_pinned' => (bool) $this->is_pinned,
            'is_read' => (bool) ($this->is_read ?? false),
            'published_at' => $this->published_at?->toISOString(),
        ];
    }
}
