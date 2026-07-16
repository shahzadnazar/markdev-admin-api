<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes any file-attachment model exposing name/file_url/file_type/size_bytes
 * (lesson resources, assignment attachments) as the portal `Resource` shape.
 */
class ResourceFileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'file_url' => $this->file_url,
            'file_type' => $this->file_type,
            'size_bytes' => $this->size_bytes !== null ? (int) $this->size_bytes : null,
        ];
    }
}
