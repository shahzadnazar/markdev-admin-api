<?php

namespace App\Support;

use App\Models\FeePlan;
use App\Models\Setting;

/** Central knobs of the installment system (admin-editable in Settings). */
class BillingConfig
{
    /** Days before the due date an installment becomes payable. */
    public static function activationDays(): int
    {
        return (int) (Setting::where('key', 'billing_activation_days')->value('value') ?? 5);
    }

    /** Warning window after the due date before the student defaults. */
    public static function graceDays(): int
    {
        return (int) (Setting::where('key', 'billing_grace_days')->value('value') ?? 5);
    }

    /** Default defaulter fine per day; a plan may override it. */
    public static function finePerDay(?FeePlan $plan = null): float
    {
        if ($plan && $plan->fine_per_day !== null) {
            return (float) $plan->fine_per_day;
        }

        return (float) (Setting::where('key', 'defaulter_fine_per_day')->value('value') ?? 100);
    }
}
