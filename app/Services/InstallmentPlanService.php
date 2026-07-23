<?php

namespace App\Services;

use App\Models\Course;
use App\Models\FeePlan;
use App\Models\Invoice;
use App\Models\User;
use App\Support\BillingConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Splits a course fee into monthly installments.
 *
 * From the admission date and a chosen due day (e.g. the 5th), one invoice
 * per month is generated. The first installment falls on the first
 * occurrence of the due day on/after admission; each next installment one
 * month later. Every invoice activates (becomes payable to the student)
 * `activationDays` before it is due.
 */
class InstallmentPlanService
{
    public function create(
        User $student,
        ?Course $course,
        string $title,
        float $totalFee,
        int $months,
        int $dueDay,
        ?Carbon $admissionDate = null,
        ?float $finePerDay = null,
        string $currency = 'PKR',
    ): FeePlan {
        $admissionDate ??= now();
        $activationDays = BillingConfig::activationDays();
        $graceDays = BillingConfig::graceDays();

        return DB::transaction(function () use (
            $student, $course, $title, $totalFee, $months, $dueDay,
            $admissionDate, $finePerDay, $currency, $activationDays, $graceDays,
        ) {
            $plan = FeePlan::create([
                'user_id' => $student->id,
                'course_id' => $course?->id,
                'title' => $title,
                'billing_cycle' => 'monthly',
                'currency' => $currency,
                'total_amount' => $totalFee,
                'installment_months' => $months,
                'due_day' => $dueDay,
                'fine_per_day' => $finePerDay,
                'starts_at' => $admissionDate->toDateString(),
                'is_active' => true,
            ]);

            // Equal split; the last installment absorbs the rounding remainder.
            $base = floor($totalFee / $months * 100) / 100;
            $last = round($totalFee - $base * ($months - 1), 2);

            $firstDue = $this->firstDueDate($admissionDate, $dueDay);
            $year = now()->year;

            foreach (range(1, $months) as $sequence) {
                $due = $this->nthDueDate($firstDue, $dueDay, $sequence - 1);
                $activates = $due->copy()->subDays($activationDays);

                $status = 'upcoming';
                if ($due->copy()->addDays($graceDays)->isPast()) {
                    $status = 'past_due';
                } elseif (! $activates->isFuture()) {
                    $status = 'open';
                }

                Invoice::create([
                    'fee_plan_id' => $plan->id,
                    'user_id' => $student->id,
                    'number' => $this->nextNumber($year),
                    'sequence_no' => $sequence,
                    'title' => sprintf('%s — Installment %d of %d (%s)', $title, $sequence, $months, $due->format('M Y')),
                    'amount' => $sequence === $months ? $last : $base,
                    'currency' => $currency,
                    'status' => $status,
                    'issued_at' => now(),
                    'activates_at' => $activates->toDateString(),
                    'due_at' => $due,
                ]);
            }

            return $plan;
        });
    }

    /** First occurrence of the due day on/after the admission date. */
    protected function firstDueDate(Carbon $admission, int $dueDay): Carbon
    {
        $candidate = $admission->copy()->startOfDay()->day(min($dueDay, $admission->daysInMonth));

        if ($candidate->lt($admission->copy()->startOfDay())) {
            $candidate = $admission->copy()->startOfDay()->addMonthNoOverflow();
            $candidate->day(min($dueDay, $candidate->daysInMonth));
        }

        return $candidate;
    }

    /** The due day N months after the first due date (clamped for short months). */
    protected function nthDueDate(Carbon $firstDue, int $dueDay, int $monthsAfter): Carbon
    {
        $due = $firstDue->copy()->startOfMonth()->addMonthsNoOverflow($monthsAfter);

        return $due->day(min($dueDay, $due->daysInMonth))->startOfDay();
    }

    protected function nextNumber(int $year): string
    {
        $sequence = (int) Invoice::withTrashed()
            ->where('number', 'like', "INV-{$year}-%")
            ->selectRaw("max(cast(substr(number, -4) as integer)) as seq")
            ->value('seq');

        return sprintf('INV-%d-%04d', $year, $sequence + 1);
    }
}
