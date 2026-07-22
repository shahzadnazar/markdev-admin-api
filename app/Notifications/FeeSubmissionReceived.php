<?php

namespace App\Notifications;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Tells billing admins a student uploaded proof of payment. */
class FeeSubmissionReceived extends Notification
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
        return [
            'title' => 'New fee submission',
            'message' => sprintf(
                '%s submitted %s %s for %s — awaiting review.',
                $this->transaction->user?->name ?? 'A student',
                $this->transaction->currency,
                number_format((float) $this->transaction->amount, 2),
                $this->transaction->invoice?->number ?? 'an invoice',
            ),
            'action_url' => '/admin/billing/submissions',
        ];
    }
}
