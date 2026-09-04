<?php

namespace App\Support;

use App\Models\AbsenceFineCharge;
use App\Models\DailyAttendance;
use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What a student's absences cost in a calendar month.
 *
 * A number of absences per month are free; each one past that costs a flat
 * amount, once. Nothing carries over — an unused February allowance is gone on
 * 1 March, the same way leave works.
 *
 * This is the ABSENCE fine and has nothing to do with `defaulter_fine_per_day`,
 * which is the late-payment fine on an overdue installment. They are separate
 * settings, separate invoice columns and separate lines on the bill.
 *
 * Months are Asia/Karachi calendar months, which is what Carbon gives here.
 */
class AbsenceFine
{
    /**
     * Absences allowed per month before anything is charged.
     *
     * The fallback is only what a database with no saved setting reports; the
     * Settings page writes the real value and everything reads it from there.
     */
    public static function allowance(): int
    {
        return max(1, (int) (Setting::cached('monthly_absent_allowance') ?? 2));
    }

    /** Charged once per absence beyond the allowance. Zero means no fine. */
    public static function perAbsence(): float
    {
        return max(0, (float) (Setting::cached('absent_fine_amount') ?? 0));
    }

    /** Absences the register holds for this student in this month. */
    public static function absencesIn(int $userId, Carbon $month): int
    {
        $start = $month->copy()->startOfMonth();

        return DailyAttendance::where('user_id', $userId)
            ->where('status', 'absent')
            // Half-open: `date` is a date-cast column, so on SQLite it comes
            // back as a full datetime and a <= against the last of the month
            // would drop that day.
            ->where('date', '>=', $start->toDateString())
            ->where('date', '<', $start->copy()->addMonth()->toDateString())
            ->count();
    }

    /**
     * @return array{allowance: int, used: int, remaining: int, chargeable: int, fine_per_absent: float, fine_total: float, currency: string, month: string, month_label: string, resets_on: string}
     */
    public static function balance(int $userId, Carbon $month): array
    {
        $allowance = static::allowance();
        $perAbsence = static::perAbsence();
        $used = static::absencesIn($userId, $month);
        $chargeable = max(0, $used - $allowance);
        $start = $month->copy()->startOfMonth();

        return [
            'allowance' => $allowance,
            'used' => $used,
            'remaining' => max(0, $allowance - $used),
            'chargeable' => $chargeable,
            'fine_per_absent' => $perAbsence,
            'fine_total' => round($chargeable * $perAbsence, 2),
            // Sent so the portal can render the amount without assuming a
            // currency of its own.
            'currency' => static::currencyFor($userId),
            'month' => $start->format('Y-m'),
            'month_label' => $start->format('F Y'),
            'resets_on' => $start->copy()->addMonth()->toDateString(),
        ];
    }

    /** The currency this student is billed in. */
    public static function currencyFor(int $userId): string
    {
        return (string) (Invoice::where('user_id', $userId)->latest('id')->value('currency') ?? 'PKR');
    }

    /**
     * Put a month's fine on the student's next invoice.
     *
     * Charged at month end rather than as each absence lands, and recorded so
     * running the command again finds the month already dealt with. A month
     * that owes nothing still gets a row: "settled at zero" and "never looked
     * at" have to be different, or a later correction has no baseline.
     *
     * @return array{charge: AbsenceFineCharge, created: bool}
     */
    public static function charge(int $userId, Carbon $month, bool $dryRun = false): array
    {
        $start = $month->copy()->startOfMonth();
        $existing = static::chargeFor($userId, $start);

        if ($existing !== null) {
            return ['charge' => $existing, 'created' => false];
        }

        $balance = static::balance($userId, $start);

        if ($dryRun) {
            return ['charge' => new AbsenceFineCharge([
                'user_id' => $userId,
                'month' => $start->toDateString(),
                'absences' => $balance['used'],
                'chargeable' => $balance['chargeable'],
                'fine_per_absent' => $balance['fine_per_absent'],
                'amount' => $balance['fine_total'],
            ]), 'created' => true];
        }

        return DB::transaction(function () use ($userId, $start, $balance) {
            $invoice = $balance['fine_total'] > 0 ? static::nextBillableInvoice($userId) : null;

            $charge = AbsenceFineCharge::create([
                'user_id' => $userId,
                'month' => $start->toDateString(),
                'absences' => $balance['used'],
                'chargeable' => $balance['chargeable'],
                'fine_per_absent' => $balance['fine_per_absent'],
                'amount' => $balance['fine_total'],
                'invoice_id' => $invoice?->id,
                'charged_at' => now(),
            ]);

            if ($invoice !== null) {
                $invoice->increment('absence_fine_amount', $balance['fine_total']);
            }

            return ['charge' => $charge, 'created' => true];
        });
    }

    /**
     * Re-check a month after its attendance changed.
     *
     * Called whenever a day stops being a chargeable absence — an admin
     * correcting it, or a leave approved for a day that had already closed.
     * If the month was never billed there is nothing to do but let the next
     * count come out lower. If it was, the difference is credited: the issued
     * invoice is never edited, paid or not.
     */
    public static function reconcile(int $userId, Carbon $month): void
    {
        $start = $month->copy()->startOfMonth();
        $charge = static::chargeFor($userId, $start);

        if ($charge === null) {
            return;
        }

        $balance = static::balance($userId, $start);
        $owed = $charge->netCharged();
        $delta = round($owed - $balance['fine_total'], 2);

        if ($delta <= 0) {
            return;
        }

        DB::transaction(function () use ($charge, $delta, $userId) {
            $invoice = static::nextBillableInvoice($userId, $charge->invoice_id);

            if ($invoice !== null) {
                $invoice->increment('absence_fine_credit', $delta);
                $charge->increment('credited_amount', $delta);

                return;
            }

            // Nowhere to put it yet; the next charge run places it.
            $charge->increment('pending_credit', $delta);
        });
    }

    /** Move any credit that had no invoice onto one now that there is. */
    public static function placePendingCredits(int $userId): void
    {
        AbsenceFineCharge::where('user_id', $userId)
            ->where('pending_credit', '>', 0)
            ->get()
            ->each(function (AbsenceFineCharge $charge) use ($userId) {
                $invoice = static::nextBillableInvoice($userId, $charge->invoice_id);

                if ($invoice === null) {
                    return;
                }

                $pending = (float) $charge->pending_credit;
                $invoice->increment('absence_fine_credit', $pending);
                $charge->increment('credited_amount', $pending);
                $charge->update(['pending_credit' => 0]);
            });
    }

    /**
     * This student's charge row for a month, matched on Y-m-d in PHP.
     *
     * `month` is a date-cast column, so an equality against "2026-09-01" finds
     * nothing on SQLite — the stored value is a full datetime. Narrowing to the
     * month with a half-open range and picking the row out here avoids that.
     */
    public static function chargeFor(int $userId, Carbon $month): ?AbsenceFineCharge
    {
        $start = $month->copy()->startOfMonth();

        return AbsenceFineCharge::where('user_id', $userId)
            ->where('month', '>=', $start->toDateString())
            ->where('month', '<', $start->copy()->addMonth()->toDateString())
            ->first();
    }

    /**
     * The invoice a charge or credit should land on: the next one the student
     * can still be asked to pay. A paid invoice is closed, whatever else
     * happens.
     */
    protected static function nextBillableInvoice(int $userId, ?int $notInvoiceId = null): ?Invoice
    {
        return Invoice::where('user_id', $userId)
            ->whereIn('status', ['open', 'past_due'])
            ->when($notInvoiceId !== null, fn ($query) => $query->where('id', '!=', $notInvoiceId))
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderBy('id')
            ->first();
    }
}
