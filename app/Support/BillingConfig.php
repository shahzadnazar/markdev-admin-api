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
        return (int) (Setting::cached('billing_activation_days') ?? 5);
    }

    /** Warning window after the due date before the student defaults. */
    public static function graceDays(): int
    {
        return (int) (Setting::cached('billing_grace_days') ?? 5);
    }

    /** One-time registration fee collected at admission (per-admission override allowed). */
    public static function registrationFee(): float
    {
        return (float) (Setting::cached('registration_fee') ?? 2000);
    }

    /** Default defaulter fine per day; a plan may override it. */
    public static function finePerDay(?FeePlan $plan = null): float
    {
        if ($plan && $plan->fine_per_day !== null) {
            return (float) $plan->fine_per_day;
        }

        return (float) (Setting::cached('defaulter_fine_per_day') ?? 100);
    }
}
