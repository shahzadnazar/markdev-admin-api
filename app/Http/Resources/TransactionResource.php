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
                'label' => $this->method_brand
                    ? "{$this->method_brand} \u{2022}\u{2022}\u{2022}\u{2022} {$this->method_last4}"
                    : Str::headline($this->method_type),
            ],
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'receipt_url' => $this->receipt_url,
        ];
    }
}
