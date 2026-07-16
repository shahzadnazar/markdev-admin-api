<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Support\AuditLogger;
use Illuminate\Console\Command;

class MarkPastDueInvoices extends Command
{
    protected $signature = 'invoices:mark-past-due';

    protected $description = 'Flag open invoices whose due date has passed as past_due';

    public function handle(): int
    {
        $flagged = 0;

        Invoice::query()
            ->where('status', 'open')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->each(function (Invoice $invoice) use (&$flagged) {
                $invoice->update(['status' => 'past_due']);
                $flagged++;
            });

        if ($flagged > 0) {
            AuditLogger::log('past_due_sweep', 'invoices', null, null, ['flagged' => $flagged]);
        }

        $this->info("Flagged {$flagged} invoice(s) as past due.");

        return self::SUCCESS;
    }
}
