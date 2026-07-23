<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\FeeSubmissionReceived;
use App\Notifications\FeeSubmissionReviewed;
use App\Support\AuditLogger;
use App\Support\BillingConfig;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The fee-verification workflow:
 *
 *  student submits proof  →  transaction `pending`, invoice `pending`
 *  admin approves         →  transaction `success`, invoice `paid`
 *  admin rejects (reason) →  transaction `rejected`, invoice back to
 *                            `open`/`past_due`, student may resubmit
 */
class FeeSubmissionService
{
    /** Payment channels students can submit through. */
    public const CHANNELS = [
        'jazzcash' => ['label' => 'JazzCash', 'method_type' => 'wallet'],
        'easypaisa' => ['label' => 'EasyPaisa', 'method_type' => 'wallet'],
        'bank_transfer' => ['label' => 'Bank transfer', 'method_type' => 'bank_transfer'],
        'cash_deposit' => ['label' => 'Cash deposit', 'method_type' => 'cash'],
        'other' => ['label' => 'Other', 'method_type' => 'other'],
    ];

    /**
     * @param array{channel: string, payer_name: string, reference_no: string, payment_date: string, notes?: string|null} $data
     */
    public function submit(User $student, Invoice $invoice, array $data, UploadedFile $receipt): Transaction
    {
        if (! in_array($invoice->status, ['open', 'past_due'], true)) {
            throw ValidationException::withMessages([
                'invoice' => $invoice->status === 'pending'
                    ? 'A submission for this invoice is already under review.'
                    : 'This invoice is not payable.',
            ]);
        }

        $channel = self::CHANNELS[$data['channel']];
        $receiptPath = $receipt->store('receipts', 'public');

        $transaction = DB::transaction(function () use ($student, $invoice, $data, $channel, $receiptPath) {
            $transaction = Transaction::create([
                'invoice_id' => $invoice->id,
                'user_id' => $student->id,
                'reference' => $this->nextReference(),
                'description' => "Student fee submission for {$invoice->number}",
                'method_type' => $channel['method_type'],
                'method_brand' => $channel['label'],
                'amount' => $invoice->payable_total,
                'currency' => $invoice->currency ?? 'PKR',
                'status' => 'pending',
                'receipt_path' => $receiptPath,
                'payer_name' => $data['payer_name'],
                'bank_name' => $channel['label'],
                'reference_no' => $data['reference_no'],
                'payment_date' => $data['payment_date'],
                'notes' => $data['notes'] ?? null,
                'submitted_by_student' => true,
            ]);

            $invoice->update(['status' => 'pending']);

            return $transaction;
        });

        AuditLogger::log('fee_submitted', 'transactions', $transaction->id, null, [
            'invoice' => $invoice->number,
            'amount' => (string) $transaction->amount,
            'channel' => $channel['label'],
            'reference_no' => $data['reference_no'],
        ], $student);

        Notification::send($this->billingReviewers(), new FeeSubmissionReceived($transaction));

        return $transaction;
    }

    public function approve(Transaction $transaction, User $reviewer): Transaction
    {
        $this->assertPending($transaction);

        DB::transaction(function () use ($transaction, $reviewer) {
            $transaction->update([
                'status' => 'success',
                'rejection_reason' => null,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            $transaction->invoice?->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);
        });

        AuditLogger::log('fee_approved', 'transactions', $transaction->id, null, [
            'invoice' => $transaction->invoice?->number,
            'amount' => (string) $transaction->amount,
        ], $reviewer);

        $transaction->user?->notify(new FeeSubmissionReviewed($transaction->fresh(['invoice', 'user'])));

        return $transaction;
    }

    public function reject(Transaction $transaction, User $reviewer, string $reason): Transaction
    {
        $this->assertPending($transaction);

        DB::transaction(function () use ($transaction, $reviewer, $reason) {
            $transaction->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            $invoice = $transaction->invoice;
            if ($invoice && $invoice->status === 'pending') {
                $graceOver = $invoice->due_at !== null
                    && $invoice->due_at->copy()->addDays(BillingConfig::graceDays())->isPast();
                $invoice->update(['status' => $graceOver ? 'past_due' : 'open']);
            }
        });

        AuditLogger::log('fee_rejected', 'transactions', $transaction->id, null, [
            'invoice' => $transaction->invoice?->number,
            'reason' => $reason,
        ], $reviewer);

        $transaction->user?->notify(new FeeSubmissionReviewed($transaction->fresh(['invoice', 'user'])));

        return $transaction;
    }

    /** Everyone allowed to review submissions (billing.manage holders). */
    protected function billingReviewers()
    {
        return User::permission('billing.manage')->get();
    }

    protected function assertPending(Transaction $transaction): void
    {
        if ($transaction->status !== 'pending' || ! $transaction->submitted_by_student) {
            throw ValidationException::withMessages([
                'transaction' => 'Only pending student submissions can be reviewed.',
            ]);
        }
    }

    protected function nextReference(): string
    {
        do {
            $reference = 'TRX-'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        } while (Transaction::where('reference', $reference)->exists());

        return $reference;
    }
}
