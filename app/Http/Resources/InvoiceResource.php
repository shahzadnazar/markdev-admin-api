<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Invoice */
class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'title' => $this->title,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'issued_at' => $this->issued_at?->toISOString(),
            'due_at' => $this->due_at?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'download_url' => $this->download_url,
            'latest_submission' => $this->whenLoaded(
                'latestSubmission',
                fn () => $this->latestSubmission
                    ? (new TransactionResource($this->latestSubmission))->resolve()
                    : null,
            ),
        ];
    }
}
