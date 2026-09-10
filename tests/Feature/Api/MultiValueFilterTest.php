<?php

namespace Tests\Feature\Api;

use App\Models\DailyAttendance;
use App\Models\Transaction;

/**
 * The portal's status filters tick several values.
 *
 * `?status=absent` became `?status[]=absent&status[]=late`, with the same
 * three rules the admin side states: an older app build still sends a scalar,
 * an empty selection is no filter, and a value the portal never offered is
 * dropped before the query sees it.
 */
class MultiValueFilterTest extends ApiTestCase
{
    protected function day(string $status, int $daysAgo): DailyAttendance
    {
        return DailyAttendance::create([
            'user_id' => auth()->id(),
            'date' => now()->subDays($daysAgo)->toDateString(),
            'status' => $status,
            'source' => 'manual',
            'marked_at' => now(),
        ]);
    }

    /* ------------------------------ Attendance ------------------------------ */

    public function test_two_statuses_return_days_of_either(): void
    {
        $this->actingAsStudent();
        $this->day('present', 1);
        $this->day('absent', 2);
        $this->day('late', 3);

        $this->getJson('/api/v1/attendance/daily?status[]=absent&status[]=late')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_an_old_single_status_url_still_filters(): void
    {
        $this->actingAsStudent();
        $this->day('present', 1);
        $this->day('absent', 2);

        $this->getJson('/api/v1/attendance/daily?status=absent')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'absent');
    }

    public function test_no_status_and_every_status_both_return_everything(): void
    {
        $this->actingAsStudent();
        $this->day('present', 1);
        $this->day('absent', 2);

        $this->getJson('/api/v1/attendance/daily')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/attendance/daily?status[]=present&status[]=absent')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_an_unknown_status_is_dropped_rather_than_matching_nothing(): void
    {
        $this->actingAsStudent();
        $this->day('present', 1);
        $this->day('absent', 2);

        // Dropped, leaving an empty selection — which is no filter. A stale or
        // hand-edited link must not read as an empty history.
        $this->getJson('/api/v1/attendance/daily?status[]=banana')
            ->assertOk()->assertJsonCount(2, 'data');

        // And a mix keeps the good one.
        $this->getJson('/api/v1/attendance/daily?status[]=absent&status[]=banana')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'absent');
    }

    public function test_the_summary_cards_ignore_the_status_filter_as_before(): void
    {
        $this->actingAsStudent();
        $this->day('present', 1);
        $this->day('absent', 2);

        // Cards that only counted the status you filtered to would say nothing;
        // that was already true and multi-select must not change it.
        $this->getJson('/api/v1/attendance/summary?status[]=absent')
            ->assertOk()
            ->assertJsonPath('data.total_sessions', 2);
    }

    /* ----------------------------- Transactions ----------------------------- */

    protected function transaction(string $status, string $reference): Transaction
    {
        return Transaction::create([
            'user_id' => auth()->id(),
            'reference' => $reference,
            'amount' => 1000,
            'status' => $status,
            'channel' => 'jazzcash',
        ]);
    }

    public function test_two_transaction_statuses_return_either(): void
    {
        $this->actingAsStudent();
        $this->transaction('success', 'TRX-A');
        $this->transaction('failed', 'TRX-B');
        $this->transaction('refunded', 'TRX-C');

        $this->getJson('/api/v1/billing/transactions?status[]=success&status[]=failed')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_an_old_single_transaction_status_still_filters(): void
    {
        $this->actingAsStudent();
        $this->transaction('success', 'TRX-A');
        $this->transaction('failed', 'TRX-B');

        $this->getJson('/api/v1/billing/transactions?status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'TRX-B');
    }

    /**
     * Invoices and transactions do not share a status vocabulary.
     *
     * The first cut of this bounded both by the transaction list, and
     * `?status=open` — the one the portal actually sends for invoices —
     * stopped matching anything. Each has its own list now.
     */
    public function test_invoice_statuses_are_their_own_list(): void
    {
        $this->assertNotContains('open', \App\Http\Controllers\Api\V1\BillingController::TRANSACTION_STATUSES);
        $this->assertContains('open', \App\Http\Controllers\Api\V1\BillingController::INVOICE_STATUSES);
    }
}
