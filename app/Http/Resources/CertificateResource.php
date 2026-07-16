<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/** @mixin \App\Models\Certificate */
class CertificateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'certificate_number' => $this->certificate_number,
            'course' => new CourseRefResource($this->course),
            'issued_at' => $this->issued_at?->toISOString(),
            'download_url' => URL::signedRoute('api.v1.certificates.download', ['certificate' => $this->id]),
            'preview_url' => null,
        ];
    }
}
