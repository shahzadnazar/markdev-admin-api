<?php

namespace Tests\Feature\Api;

use App\Models\FeePlan;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

class BillingTest extends ApiTestCase
{
    /** @return array{0: FeePlan, 1: Invoice, 2: Invoice} */
    protected function makePlan(User $user): array
    {
        $plan = FeePlan::create([
            'user_id' => $user->id,
            'title' => 'Advanced Web Development',
            'billing_cycle' => 'annual',
            'currency' => 'USD',
            'total_amount' => 1200.00,
            'is_active' => true,
        ]);

        $paid = Invoice::create([
            'fee_plan_id' => $plan->id,
            'user_id' => $user->id,
            'number' => 'INV-2026-1001',
            'title' => 'Installment 1',
            'amount' => 400.00,
            'currency' => 'USD',
            'status' => 'paid',
            'issued_at' => now()->subMonths(2),
            'due_at' => now()->subMonth(),
            'paid_at' => now()->subMonth(),
        ]);

        $open = Invoice::create([
            'fee_plan_id' => $plan->id,
            'user_id' => $user->id,
            'number' => 'INV-2026-1002',
            'title' => 'Installment 2',
            'amount' => 400.00,
            'currency' => 'USD',
            'status' => 'open',
            'issued_at' => now()->subDays(10),
            'due_at' => now()->addMonth(),
        ]);

        return [$plan, $paid, $open];
    }

    public function test_overview_with_an_active_plan(): void
    {
        $user = $this->actingAsStudent();
        [, , $open] = $this->makePlan($user);

        $this->getJson('/api/v1/billing')->assertOk()
            ->assertJsonPath('data.plan_title', 'Advanced Web Development')
            ->assertJsonPath('data.billing_cycle', 'annual')
            ->assertJsonPath('data.billing_active', true)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.total_amount', 1200)
            ->assertJsonPath('data.paid_amount', 400)
            ->assertJsonPath('data.remaining_amount', 800)
            ->assertJsonPath('data.paid_percent', 33.3)
            ->assertJsonPath('data.next_invoice.id', $open->id)
            ->assertJsonPath('data.next_invoice.number', 'INV-2026-1002')
            ->assertJsonPath('data.statement_url', null);
    }

    public function test_overview_without_a_plan(): void
    {
        $this->actingAsStudent();

        $this->getJson('/api/v1/billing')->assertOk()
            ->assertJsonPath('data.plan_title', null)
            ->assertJsonPath('data.billing_active', false)
            ->assertJsonPath('data.total_amount', 0)
            ->assertJsonPath('data.paid_percent', 0)
            ->assertJsonPath('data.next_invoice', null);
    }

    public function test_transactions_list_with_method_labels_and_filters(): void
    {
        $user = $this->actingAsStudent();

        Transaction::create([
            'user_id' => $user->id, 'reference' => 'TRX-11111', 'method_type' => 'card',
            'method_brand' => 'Visa', 'method_last4' => '4242',
            'amount' => 400, 'currency' => 'USD', 'status' => 'success',
        ]);
        Transaction::create([
            'user_id' => $user->id, 'reference' => 'TRX-22222', 'method_type' => 'bank_transfer',
            'amount' => 400, 'currency' => 'USD', 'status' => 'failed',
        ]);

        // Someone else's transaction stays hidden.
        Transaction::create([
            'user_id' => $this->student()->id, 'reference' => 'TRX-33333', 'method_type' => 'cash',
            'amount' => 100, 'currency' => 'USD', 'status' => 'success',
        ]);

        $response = $this->getJson('/api/v1/billing/transactions')->assertOk();
        $response->assertJsonCount(2, 'data');

        $labels = collect($response->json('data'))->keyBy('reference');
        $this->assertSame("Visa \u{2022}\u{2022}\u{2022}\u{2022} 4242", $labels['TRX-11111']['method']['label']);
        $this->assertSame('Bank Transfer', $labels['TRX-22222']['method']['label']);

        $this->getJson('/api/v1/billing/transactions?status=failed')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'TRX-22222');
    }

    public function test_invoices_list_with_status_filter(): void
    {
        $user = $this->actingAsStudent();
        $this->makePlan($user);

        $this->getJson('/api/v1/billing/invoices')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/billing/invoices?status=open')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'open');
    }

    public function test_paying_an_open_invoice_settles_it(): void
    {
        $user = $this->actingAsStudent();
        [, , $open] = $this->makePlan($user);

        $response = $this->postJson("/api/v1/billing/invoices/{$open->id}/pay")->assertOk();

        $response->assertJsonPath('data.checkout_url', null)
            ->assertJsonPath('data.transaction.status', 'success')
            ->assertJsonPath('data.transaction.invoice_id', $open->id)
            ->assertJsonPath('data.transaction.amount', 400)
            ->assertJsonPath('data.transaction.method.label', "Visa \u{2022}\u{2022}\u{2022}\u{2022} 4242");

        $this->assertMatchesRegularExpression('/^TRX-\d{5}$/', $response->json('data.transaction.reference'));

        $open->refresh();
        $this->assertSame('paid', $open->status);
        $this->assertNotNull($open->paid_at);

        // Overview reflects the payment.
        $this->getJson('/api/v1/billing')->assertOk()
            ->assertJsonPath('data.paid_amount', 800)
            ->assertJsonPath('data.next_invoice', null);
    }

    public function test_paying_a_settled_invoice_is_rejected(): void
    {
        $user = $this->actingAsStudent();
        [, $paid] = $this->makePlan($user);

        $this->postJson("/api/v1/billing/invoices/{$paid->id}/pay")
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice');
    }

    public function test_paying_someone_elses_invoice_is_forbidden(): void
    {
        $owner = $this->student();
        [, , $open] = $this->makePlan($owner);

        Sanctum::actingAs($this->student());

        $this->postJson("/api/v1/billing/invoices/{$open->id}/pay")->assertForbidden();
        $this->assertSame('open', $open->fresh()->status);
    }

    public function test_retrying_a_failed_transaction_settles_the_invoice(): void
    {
        $user = $this->actingAsStudent();
        [, , $open] = $this->makePlan($user);

        $failed = Transaction::create([
            'invoice_id' => $open->id, 'user_id' => $user->id, 'reference' => 'TRX-90001',
            'method_type' => 'card', 'method_brand' => 'Mastercard', 'method_last4' => '4444',
            'amount' => 400, 'currency' => 'USD', 'status' => 'failed',
        ]);

        $this->postJson("/api/v1/billing/transactions/{$failed->id}/retry")->assertOk()
            ->assertJsonPath('data.transaction.status', 'success')
            ->assertJsonPath('data.transaction.method.brand', 'Mastercard');

        $this->assertSame('paid', $open->fresh()->status);
    }

    public function test_only_failed_transactions_can_be_retried(): void
    {
        $user = $this->actingAsStudent();
        [, , $open] = $this->makePlan($user);

        $success = Transaction::create([
            'invoice_id' => $open->id, 'user_id' => $user->id, 'reference' => 'TRX-90002',
            'method_type' => 'card', 'amount' => 400, 'currency' => 'USD', 'status' => 'success',
        ]);

        $this->postJson("/api/v1/billing/transactions/{$success->id}/retry")->assertStatus(422);

        // Cross-user retry is forbidden.
        $foreign = Transaction::create([
            'user_id' => $this->student()->id, 'reference' => 'TRX-90003',
            'method_type' => 'card', 'amount' => 100, 'currency' => 'USD', 'status' => 'failed',
        ]);
        $this->postJson("/api/v1/billing/transactions/{$foreign->id}/retry")->assertForbidden();
    }
}
