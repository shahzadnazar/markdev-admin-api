<?php

namespace App\Notifications;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Tells the student their fee submission was approved or rejected. */
class FeeSubmissionReviewed extends Notification
{
    use Queueable;

    public function __construct(public Transaction $transaction)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $approved = $this->transaction->status === 'success';
        $invoice = $this->transaction->invoice?->number ?? 'your invoice';

        return [
            'title' => $approved ? 'Payment approved' : 'Payment rejected',
            'message' => $approved
                ? "Your payment for {$invoice} was verified — the invoice is now marked paid."
                : "Your payment for {$invoice} was rejected: {$this->transaction->rejection_reason}",
            'action_url' => '/payments',
        ];
    }
}
