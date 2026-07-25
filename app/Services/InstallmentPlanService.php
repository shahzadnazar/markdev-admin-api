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
 *
 * Admission mode (`advance: true`) matches the counter workflow: the first
 * installment is due on the admission day itself, the rest follow the
 * monthly due-day cycle. Its amount may differ (`firstAmount`) — the
 * remaining fee is split equally over the remaining months. An optional
 * one-time `registrationFee` becomes its own invoice, also due on admission.
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
        bool $advance = false,
        ?float $firstAmount = null,
        float $registrationFee = 0,
    ): FeePlan {
        $admissionDate ??= now();
        $activationDays = BillingConfig::activationDays();
        $graceDays = BillingConfig::graceDays();

        return DB::transaction(function () use (
            $student, $course, $title, $totalFee, $months, $dueDay, $admissionDate,
            $finePerDay, $currency, $activationDays, $graceDays, $advance, $firstAmount, $registrationFee,
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

            $year = now()->year;

            if ($registrationFee > 0) {
                Invoice::create([
                    'fee_plan_id' => $plan->id,
                    'user_id' => $student->id,
                    'number' => $this->nextNumber($year),
                    'type' => 'registration',
                    'sequence_no' => null,
                    'title' => sprintf('%s — Registration fee', $title),
                    'amount' => round($registrationFee, 2),
                    'currency' => $currency,
                    'status' => $this->statusFor($admissionDate->copy()->startOfDay(), $admissionDate->copy()->startOfDay(), $graceDays),
                    'issued_at' => now(),
                    'activates_at' => $admissionDate->toDateString(),
                    'due_at' => $admissionDate->copy()->startOfDay(),
                ]);
            }

            $amounts = $this->splitAmounts($totalFee, $months, $advance ? $firstAmount : null);

            // Advance mode: installment 1 is due at admission, the cycle
            // (due-day months) starts strictly after the admission day.
            $cycleStart = $advance
                ? $this->firstDueDate($admissionDate->copy()->addDay(), $dueDay)
                : $this->firstDueDate($admissionDate, $dueDay);

            foreach (range(1, $months) as $sequence) {
                $due = ($advance && $sequence === 1)
                    ? $admissionDate->copy()->startOfDay()
                    : $this->nthDueDate($cycleStart, $dueDay, $sequence - ($advance ? 2 : 1));
                $activates = $due->copy()->subDays($activationDays);

                Invoice::create([
                    'fee_plan_id' => $plan->id,
                    'user_id' => $student->id,
                    'number' => $this->nextNumber($year),
                    'sequence_no' => $sequence,
                    'title' => $this->installmentTitle($title, $sequence, $months, $due, $advance),
                    'amount' => $amounts[$sequence - 1],
                    'currency' => $currency,
                    'status' => $this->statusFor($due, $activates, $graceDays),
                    'issued_at' => now(),
                    'activates_at' => $activates->toDateString(),
                    'due_at' => $due,
                ]);
            }

            return $plan;
        });
    }

    /**
     * Per-installment amounts. Equal split by default; with a custom first
     * amount the remainder divides equally over the remaining months. The
     * last installment always absorbs the rounding difference.
     *
     * @return array<int, float>
     */
    protected function splitAmounts(float $totalFee, int $months, ?float $firstAmount): array
    {
        if ($months === 1) {
            return [round($totalFee, 2)];
        }

        if ($firstAmount === null) {
            $base = floor($totalFee / $months * 100) / 100;
            $amounts = array_fill(0, $months, $base);
            $amounts[$months - 1] = round($totalFee - $base * ($months - 1), 2);

            return $amounts;
        }

        $first = round($firstAmount, 2);
        $remaining = round($totalFee - $first, 2);
        $rest = $months - 1;
        $base = floor($remaining / $rest * 100) / 100;

        $amounts = array_fill(0, $months, $base);
        $amounts[0] = $first;
        $amounts[$months - 1] = round($remaining - $base * ($rest - 1), 2);

        return $amounts;
    }

    protected function installmentTitle(string $title, int $sequence, int $months, Carbon $due, bool $advance): string
    {
        if ($months === 1) {
            return sprintf('%s — Full payment (%s)', $title, $due->format('M Y'));
        }

        if ($advance && $sequence === 1) {
            return sprintf('%s — Installment 1 of %d — advance (%s)', $title, $months, $due->format('M Y'));
        }

        return sprintf('%s — Installment %d of %d (%s)', $title, $sequence, $months, $due->format('M Y'));
    }

    protected function statusFor(Carbon $due, Carbon $activates, int $graceDays): string
    {
        if ($due->copy()->addDays($graceDays)->isPast()) {
            return 'past_due';
        }

        return $activates->isFuture() ? 'upcoming' : 'open';
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
