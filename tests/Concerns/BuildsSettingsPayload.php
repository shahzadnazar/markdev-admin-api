<?php

namespace Tests\Concerns;

use App\Support\ProgressWeights;

/**
 * A complete, valid Settings form body.
 *
 * The settings page is ONE form, so every field it renders is required and a
 * test posting a subset gets a validation error rather than the thing it meant
 * to check. Five test classes had each grown their own copy of this array, and
 * every new required setting broke all five at once — which is what happened
 * when the progress components landed.
 *
 * One builder instead. A new required setting is added here, once, and the
 * comment beside it says which release made it required, so the next person
 * can tell a deliberate default from a number somebody typed.
 */
trait BuildsSettingsPayload
{
    /** @param  array<string, mixed>  $overrides */
    protected function settingsPayload(array $overrides = []): array
    {
        $defaults = [
            'site_name' => 'MarkDev',
            'registration_fee' => 2000,
            'defaulter_fine_per_day' => 100,
            'billing_grace_days' => 5,
            'billing_activation_days' => 5,
            'attendance_day_start_hour' => 9,
            'attendance_day_start_minute' => 0,
            'attendance_day_start_meridiem' => 'AM',
            'attendance_late_after_minutes' => 15,
            'academy_working_days' => [1, 2, 3, 4, 5],
            // Required since the holiday announcer landed: how far ahead a
            // closure is announced, minimum 1.
            'holiday_announce_days_before' => 1,
            // Required since the weights moved out of the constant (cdcdc45).
            'attendance_weight_present' => 100,
            'attendance_weight_late' => 70,
            'attendance_weight_leave' => 50,
            'attendance_weight_absent' => 0,
            'monthly_leave_allowance' => 2,
            'monthly_absent_allowance' => 2,
            'absent_fine_amount' => 500,
            'attendance_mode' => 'manual',
            'quiz_default_attempts' => 1,
            'quiz_seconds_per_question' => 30,
        ];

        // The progress components, at their defaults — checked and totalling
        // 100, which is what the validator insists on. Built from the constant
        // so a fifth component cannot be added without these following.
        foreach (ProgressWeights::DEFAULTS as $component => $percent) {
            $defaults['progress_weight_'.$component] = $percent;
            $defaults['progress_enabled_'.$component] = 1;
        }

        return array_merge($defaults, $overrides);
    }
}
