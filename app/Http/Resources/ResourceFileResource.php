<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes any attachment model exposing name/file_url/file_type/size_bytes
 * (lesson resources, assignment attachments) as the portal `Resource` shape.
 *
 * Lesson resources may also be LINKS rather than files. Assignment attachments
 * cannot, and have no `kind` or `url` column at all — hence the defaults:
 * anything without the columns is a file, which is what it has always been.
 */
class ResourceFileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind ?? 'file',
            // One field the UI can follow whatever the kind, so nothing
            // downstream has to know which column a resource lives in.
            'url' => $this->target_url ?? $this->file_url,
            'is_youtube' => (bool) ($this->is_youtube ?? false),
            'file_url' => $this->file_url,
            'file_type' => $this->file_type,
            'size_bytes' => $this->size_bytes !== null ? (int) $this->size_bytes : null,
        ];
    }
}
