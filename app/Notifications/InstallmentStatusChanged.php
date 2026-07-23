<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Support\BillingConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Due-soon / grace-warning / defaulted notices for an installment. */
class InstallmentStatusChanged extends Notification
{
    use Queueable;

    public function __construct(
        public Invoice $invoice,
        public string $stage, // due_soon | grace_warning | defaulted
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $amount = $this->invoice->currency.' '.number_format((float) $this->invoice->amount, 0);
        $due = $this->invoice->due_at?->format('M j');
        $graceEnd = $this->invoice->due_at?->copy()->addDays(BillingConfig::graceDays())->format('M j');

        [$title, $message] = match ($this->stage) {
            'due_soon' => [
                'Fee due soon',
                "Your installment of {$amount} is due on {$due}. It's now open for payment.",
            ],
            'grace_warning' => [
                'Fee overdue — grace period',
                "Your installment of {$amount} was due on {$due}. Pay by {$graceEnd} to avoid a daily fine.",
            ],
            default => [
                'Fee defaulted — fine applying',
                "Your installment of {$amount} is now in default. A fine of {$this->invoice->currency} ".
                number_format(BillingConfig::finePerDay($this->invoice->feePlan), 0).
                ' per day is being added until you pay.',
            ],
        };

        return ['title' => $title, 'message' => $message, 'action_url' => '/payments'];
    }
}
