<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Comment */
class CommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lesson_id' => $this->lesson_id,
            'parent_id' => $this->parent_id,
            'body' => $this->body,
            'author' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'avatar_url' => $this->user?->avatar_url,
            ],
            'created_at' => $this->created_at?->toISOString(),
            'replies' => CommentResource::collection($this->whenLoaded('replies')),
        ];
    }
}
