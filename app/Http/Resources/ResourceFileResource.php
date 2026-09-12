<?php

namespace App\Http\Resources;

use App\Support\PrivateFiles;
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
        /*
         * Signed, because the portal cannot send its bearer token on an
         * <a href> or an <img src>. The signature carries WHO is asking across
         * that hop; FileController still checks enrollment when the link is
         * followed, so a signed link to material you are not enrolled in is
         * still a 403.
         *
         * Two models arrive here — lesson/course resources and assignment
         * attachments — and they are served by different routes, so the route
         * name comes from the model rather than from this shape.
         */
        $fileUrl = $this->file_path
            ? PrivateFiles::signedUrl(
                $this->resource instanceof \App\Models\LessonResource ? 'files.resource' : 'files.attachment',
                [$this->id],
                $request->user(),
            )
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind ?? 'file',
            // One field the UI can follow whatever the kind, so nothing
            // downstream has to know which column a resource lives in.
            'url' => ($this->kind ?? 'file') === 'link' ? $this->url : $fileUrl,
            'is_youtube' => (bool) ($this->is_youtube ?? false),
            'file_url' => $fileUrl,
            'file_type' => $this->file_type,
            'size_bytes' => $this->size_bytes !== null ? (int) $this->size_bytes : null,
        ];
    }
}
