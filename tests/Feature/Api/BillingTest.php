<?php

namespace Tests\Feature\Api;

use App\Models\FeePlan;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\FeeSubmissionReceived;
use App\Notifications\FeeSubmissionReviewed;
use App\Services\FeeSubmissionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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

    protected function submit(Invoice $invoice, array $overrides = [])
    {
        return $this->post("/api/v1/billing/invoices/{$invoice->id}/submissions", array_merge([
            'channel' => 'jazzcash',
            'payer_name' => 'Shahzad Student',
            'reference_no' => 'JC-12345',
            'payment_date' => now()->subDay()->toDateString(),
            'notes' => 'Paid from my JazzCash account.',
            'receipt' => UploadedFile::fake()->image('receipt.jpg', 800, 1200),
        ], $overrides), ['Accept' => 'application/json']);
    }

    public function test_submitting_proof_of_payment_marks_everything_pending(): void
    {
        Storage::fake('public');
        Notification::fake();

        $admin = User::factory()->create(['email' => 'reviewer@markdev.test']);
        $admin->assignRole('admin');

        $user = $this->actingAsStudent();
        [, , $open] = $this->makePlan($user);

        $response = $this->submit($open)->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.invoice_id', $open->id)
            ->assertJsonPath('data.submitted_by_student', true)
            ->assertJsonPath('data.method.label', 'JazzCash')
            ->assertJsonPath('data.reference_no', 'JC-12345');

        $this->assertMatchesRegularExpression('/^TRX-\d{5}$/', $response->json('data.reference'));
        $this->assertSame('pending', $open->fresh()->status);

        $transaction = Transaction::find($response->json('data.id'));
        Storage::disk('public')->assertExists($transaction->receipt_path);

        // Billing reviewers are notified.
        Notification::assertSentTo($admin, FeeSubmissionReceived::class);

        // Overview exposes the pending state.
        $this->getJson('/api/v1/billing')->assertOk()
            ->assertJsonPath('data.pending_review_amount', 400)
            ->assertJsonPath('data.pending_invoice.id', $open->id)
            ->assertJsonPath('data.pending_invoice.latest_submission.status', 'pending');
    }

    public function test_submission_requires_all_compulsory_fields(): void
    {
        $user = $this->actingAsStudent();
        [, , $open] = $this->makePlan($user);

        $this->post("/api/v1/billing/invoices/{$open->id}/submissions", [], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel', 'payer_name', 'reference_no', 'payment_date', 'receipt']);
    }

    public function test_cannot_submit_twice_or_for_settled_invoices(): void
    {
        Storage::fake('public');
        Notification::fake();

        $user = $this->actingAsStudent();
        [, $paid, $open] = $this->makePlan($user);

        $this->submit($open)->assertStatus(201);
        $this->submit($open)->assertStatus(422)->assertJsonValidationErrors('invoice');
        $this->submit($paid)->assertStatus(422)->assertJsonValidationErrors('invoice');
    }

    public function test_cannot_submit_for_someone_elses_invoice(): void
    {
        Storage::fake('public');

        $owner = $this->student();
        [, , $open] = $this->makePlan($owner);

        Sanctum::actingAs($this->student());

        $this->submit($open)->assertForbidden();
        $this->assertSame('open', $open->fresh()->status);
    }

    public function test_admin_approval_marks_the_invoice_paid_and_notifies_the_student(): void
    {
        Storage::fake('public');
        Notification::fake();

        $reviewer = User::factory()->create();
        $reviewer->assignRole('admin');

        $user = $this->actingAsStudent();
        [, , $open] = $this->makePlan($user);
        $this->submit($open)->assertStatus(201);

        $transaction = Transaction::where('invoice_id', $open->id)->first();
        app(FeeSubmissionService::class)->approve($transaction, $reviewer);

        $transaction->refresh();
        $this->assertSame('success', $transaction->status);
        $this->assertSame($reviewer->id, $transaction->reviewed_by);
        $this->assertSame('paid', $open->fresh()->status);
        Notification::assertSentTo($user, FeeSubmissionReviewed::class);
    }

    public function test_admin_rejection_reopens_the_invoice_with_a_reason_and_allows_resubmit(): void
    {
        Storage::fake('public');
        Notification::fake();

        $reviewer = User::factory()->create();
        $reviewer->assignRole('admin');

        $user = $this->actingAsStudent();
        [, , $open] = $this->makePlan($user);
        $this->submit($open)->assertStatus(201);

        $transaction = Transaction::where('invoice_id', $open->id)->first();
        app(FeeSubmissionService::class)->reject($transaction, $reviewer, 'Receipt amount does not match the invoice.');

        $transaction->refresh();
        $this->assertSame('rejected', $transaction->status);
        $this->assertSame('Receipt amount does not match the invoice.', $transaction->rejection_reason);
        $this->assertSame('open', $open->fresh()->status);
        Notification::assertSentTo($user, FeeSubmissionReviewed::class);

        // The invoice list surfaces the rejection so the student can resubmit.
        $this->getJson('/api/v1/billing/invoices')->assertOk()
            ->assertJsonPath('data.0.latest_submission.status', 'rejected')
            ->assertJsonPath('data.0.latest_submission.rejection_reason', 'Receipt amount does not match the invoice.');

        // Resubmission works after a rejection.
        $this->submit($open, ['reference_no' => 'JC-99999'])->assertStatus(201);
        $this->assertSame('pending', $open->fresh()->status);
    }
}
