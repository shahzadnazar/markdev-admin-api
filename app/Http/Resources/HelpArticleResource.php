<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Help article. `body` is only rendered on the detail endpoint, where the
 * controller flags the model with `with_body`.
 *
 * @mixin \App\Models\HelpArticle
 */
class HelpArticleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'body' => ($this->with_body ?? false) ? $this->body : null,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
