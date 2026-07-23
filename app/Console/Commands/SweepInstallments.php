<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Notifications\InstallmentStatusChanged;
use App\Support\AuditLogger;
use App\Support\BillingConfig;
use Illuminate\Console\Command;

/**
 * The daily heartbeat of the installment system:
 *  1. activate upcoming installments that enter their payment window
 *  2. warn students whose due date passed (grace period)
 *  3. mark defaulters once grace ends and accrue the daily fine
 */
class SweepInstallments extends Command
{
    protected $signature = 'billing:sweep';

    protected $description = 'Activate installments, warn overdue students, mark defaulters and accrue fines';

    public function handle(): int
    {
        $graceDays = BillingConfig::graceDays();
        $activated = $warned = $defaulted = $fined = 0;

        // 1. upcoming → open when the activation window starts
        Invoice::query()
            ->where('status', 'upcoming')
            ->whereDate('activates_at', '<=', today())
            ->with('user')
            ->each(function (Invoice $invoice) use (&$activated) {
                $invoice->update(['status' => 'open']);
                $invoice->user?->notify(new InstallmentStatusChanged($invoice, 'due_soon'));
                $activated++;
            });

        // 2. grace warning, once, when the due date has passed
        Invoice::query()
            ->where('status', 'open')
            ->whereNull('grace_notified_at')
            ->whereDate('due_at', '<', today())
            ->with('user')
            ->each(function (Invoice $invoice) use (&$warned) {
                $invoice->update(['grace_notified_at' => now()]);
                $invoice->user?->notify(new InstallmentStatusChanged($invoice, 'grace_warning'));
                $warned++;
            });

        // 3. open → past_due after grace, then accrue the daily fine
        Invoice::query()
            ->whereIn('status', ['open', 'past_due'])
            ->whereNotNull('due_at')
            ->whereDate('due_at', '<', today()->subDays($graceDays))
            ->with(['user', 'feePlan'])
            ->each(function (Invoice $invoice) use ($graceDays, &$defaulted, &$fined) {
                $becameDefaulter = $invoice->status !== 'past_due';

                $fineDays = max(0, (int) $invoice->due_at->copy()->addDays($graceDays)->startOfDay()
                    ->diffInDays(today()));
                $finePerDay = BillingConfig::finePerDay($invoice->feePlan);
                $fineAmount = round($fineDays * $finePerDay, 2);

                $dirty = $becameDefaulter
                    || (float) $invoice->fine_amount !== $fineAmount
                    || $invoice->fine_days !== $fineDays;

                if ($dirty) {
                    $invoice->update([
                        'status' => 'past_due',
                        'fine_days' => $fineDays,
                        'fine_amount' => $fineAmount,
                    ]);
                    $fined++;
                }

                if ($becameDefaulter) {
                    $invoice->user?->notify(new InstallmentStatusChanged($invoice, 'defaulted'));
                    $defaulted++;
                }
            });

        if ($activated + $warned + $defaulted + $fined > 0) {
            AuditLogger::log('installment_sweep', 'invoices', null, null, [
                'activated' => $activated,
                'grace_warnings' => $warned,
                'new_defaulters' => $defaulted,
                'fines_updated' => $fined,
            ]);
        }

        $this->info("Activated {$activated}, warned {$warned}, defaulted {$defaulted}, fines updated on {$fined} invoice(s).");

        return self::SUCCESS;
    }
}
