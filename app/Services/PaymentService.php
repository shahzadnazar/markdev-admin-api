<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;

/**
 * Simulated payment gateway: settles an invoice immediately with a successful
 * transaction. A real hosted-checkout integration would return a checkout_url
 * instead.
 */
class PaymentService
{
    /** @param  array{type?: string, brand?: string|null, last4?: string|null}  $method */
    public function settleInvoice(User $user, Invoice $invoice, array $method = []): Transaction
    {
        $transaction = Transaction::create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'reference' => $this->uniqueReference(),
            'description' => "Payment for {$invoice->number}",
            'method_type' => $method['type'] ?? 'card',
            'method_brand' => array_key_exists('brand', $method) ? $method['brand'] : 'Visa',
            'method_last4' => array_key_exists('last4', $method) ? $method['last4'] : '4242',
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'status' => 'success',
        ]);

        $invoice->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return $transaction;
    }

    public function uniqueReference(): string
    {
        do {
            $reference = 'TRX-'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        } while (Transaction::where('reference', $reference)->exists());

        return $reference;
    }
}
