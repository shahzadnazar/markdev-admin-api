<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Support\BillingConfig;
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
            'sequence_no' => $this->sequence_no,
            'title' => $this->title,
            'amount' => (float) $this->amount,
            'fine_amount' => (float) $this->fine_amount,
            'fine_days' => (int) $this->fine_days,
            'payable_total' => $this->payable_total,
            'currency' => $this->currency,
            'status' => $this->status,
            'issued_at' => $this->issued_at?->toISOString(),
            'activates_at' => $this->activates_at?->toDateString(),
            'due_at' => $this->due_at?->toISOString(),
            'in_grace' => $this->isInGrace(BillingConfig::graceDays()),
            'days_overdue' => $this->daysOverdue(),
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
