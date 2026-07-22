<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin \App\Models\Transaction */
class TransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'invoice_id' => $this->invoice_id,
            'description' => $this->description,
            'method' => [
                'type' => $this->method_type,
                'brand' => $this->method_brand,
                'last4' => $this->method_last4,
                'label' => $this->method_brand && $this->method_last4
                    ? "{$this->method_brand} \u{2022}\u{2022}\u{2022}\u{2022} {$this->method_last4}"
                    : ($this->method_brand ?: Str::headline($this->method_type)),
            ],
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'receipt_url' => $this->receipt_url,
            'payer_name' => $this->payer_name,
            'bank_name' => $this->bank_name,
            'reference_no' => $this->reference_no,
            'payment_date' => $this->payment_date?->toDateString(),
            'notes' => $this->notes,
            'submitted_by_student' => (bool) $this->submitted_by_student,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
        ];
    }
}
