<?php

namespace Tests\Feature\Api;

use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\FeeSubmissionReviewed;
use App\Notifications\InstallmentStatusChanged;
use App\Services\FeeSubmissionService;
use App\Services\InstallmentPlanService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class InstallmentTest extends ApiTestCase
{
    protected function plan(User $student, ?Carbon $admission = null, int $months = 6)
    {
        return app(InstallmentPlanService::class)->create(
            student: $student,
            course: null,
            title: 'Full Stack Web Development',
            totalFee: 90000,
            months: $months,
            dueDay: 5,
            admissionDate: $admission,
            currency: 'PKR',
        );
    }

    public function test_schedule_is_generated_from_the_admission_date(): void
    {
        Carbon::setTestNow('2026-07-22');

        $student = $this->student();
        $plan = $this->plan($student, Carbon::parse('2026-07-10'));

        $invoices = $plan->invoices()->orderBy('sequence_no')->get();

        $this->assertCount(6, $invoices);
        // Admission on Jul 10 → the 5th already passed → first due Aug 5.
        $this->assertSame('2026-08-05', $invoices[0]->due_at->toDateString());
        $this->assertSame('2026-07-31', $invoices[0]->activates_at->toDateString());
        $this->assertSame('2027-01-05', $invoices[5]->due_at->toDateString());
        // Equal split, all upcoming (nothing active yet on Jul 22... activation Jul 31).
        $this->assertSame([15000.0], $invoices->pluck('amount')->map(fn ($a) => (float) $a)->unique()->values()->all());
        $this->assertSame(['upcoming'], $invoices->pluck('status')->unique()->values()->all());
        $this->assertSame(90000.0, (float) $invoices->sum('amount'));

        Carbon::setTestNow();
    }

    public function test_admission_before_due_day_uses_the_same_month(): void
    {
        Carbon::setTestNow('2026-07-22');

        $plan = $this->plan($this->student(), Carbon::parse('2026-07-03'));

        $first = $plan->invoices()->orderBy('sequence_no')->first();
        $this->assertSame('2026-07-05', $first->due_at->toDateString());
        // Due+grace (Jul 10) already passed on Jul 22 → seeded as defaulter.
        $this->assertSame('past_due', $first->status);

        Carbon::setTestNow();
    }

    public function test_sweep_activates_warns_defaults_and_accrues_the_daily_fine(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-07-01');

        $student = $this->student();
        $plan = $this->plan($student, Carbon::parse('2026-07-01')); // first due Jul 5, activates Jun 30 → open

        $first = $plan->invoices()->orderBy('sequence_no')->first();
        $this->assertSame('open', $first->status);

        // Due date passes → grace warning, still payable, no fine.
        Carbon::setTestNow('2026-07-07');
        Artisan::call('billing:sweep');
        $first->refresh();
        $this->assertSame('open', $first->status);
        $this->assertNotNull($first->grace_notified_at);
        Notification::assertSentTo($student, InstallmentStatusChanged::class, fn ($n) => $n->stage === 'grace_warning');

        // Grace (5 days) ends Jul 10 → defaulter with a daily fine of 100.
        Carbon::setTestNow('2026-07-13');
        Artisan::call('billing:sweep');
        $first->refresh();
        $this->assertSame('past_due', $first->status);
        $this->assertSame(3, $first->fine_days);
        $this->assertSame(300.0, (float) $first->fine_amount);
        $this->assertSame(15300.0, $first->payable_total);
        Notification::assertSentTo($student, InstallmentStatusChanged::class, fn ($n) => $n->stage === 'defaulted');

        // Next day the fine grows; the sweep is idempotent per day.
        Carbon::setTestNow('2026-07-14');
        Artisan::call('billing:sweep');
        Artisan::call('billing:sweep');
        $this->assertSame(400.0, (float) $first->fresh()->fine_amount);

        Carbon::setTestNow();
    }

    public function test_plan_fine_override_beats_the_global_setting(): void
    {
        Notification::fake();
        Setting::updateOrCreate(['key' => 'defaulter_fine_per_day'], ['value' => 100]);
        Carbon::setTestNow('2026-07-01');

        $student = $this->student();
        $plan = app(InstallmentPlanService::class)->create(
            student: $student, course: null, title: 'Plan', totalFee: 30000,
            months: 2, dueDay: 5, admissionDate: Carbon::parse('2026-07-01'),
            finePerDay: 250, currency: 'PKR',
        );

        Carbon::setTestNow('2026-07-12'); // 2 days past grace end (Jul 10)
        Artisan::call('billing:sweep');

        $this->assertSame(500.0, (float) $plan->invoices()->orderBy('sequence_no')->first()->fine_amount);

        Carbon::setTestNow();
    }

    public function test_upcoming_installments_cannot_be_paid(): void
    {
        Storage::fake('public');
        Carbon::setTestNow('2026-07-22');

        $student = $this->actingAsStudent();
        $plan = $this->plan($student, Carbon::parse('2026-07-10')); // all upcoming

        $upcoming = $plan->invoices()->orderBy('sequence_no')->first();

        $this->post("/api/v1/billing/invoices/{$upcoming->id}/submissions", [
            'channel' => 'jazzcash',
            'payer_name' => 'S',
            'reference_no' => 'X',
            'payment_date' => '2026-07-21',
            'receipt' => UploadedFile::fake()->image('r.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('invoice');

        Carbon::setTestNow();
    }

    public function test_defaulter_pays_installment_plus_fine_and_approval_settles_it(): void
    {
        Storage::fake('public');
        Notification::fake();
        Carbon::setTestNow('2026-07-01');

        $student = $this->actingAsStudent();
        $plan = $this->plan($student, Carbon::parse('2026-07-01'));

        Carbon::setTestNow('2026-07-15'); // 5 fine days after grace end Jul 10
        Artisan::call('billing:sweep');

        $invoice = $plan->invoices()->orderBy('sequence_no')->first()->fresh();
        $this->assertSame(15500.0, $invoice->payable_total);

        $response = $this->post("/api/v1/billing/invoices/{$invoice->id}/submissions", [
            'channel' => 'bank_transfer',
            'payer_name' => 'Shahzad Student',
            'reference_no' => 'FT-1',
            'payment_date' => '2026-07-15',
            'receipt' => UploadedFile::fake()->image('slip.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        // The submission charges installment + fine.
        $this->assertSame(15500.0, (float) $response->json('data.amount'));

        $reviewer = User::factory()->create();
        $reviewer->assignRole('admin');
        $transaction = Transaction::find($response->json('data.id'));
        app(FeeSubmissionService::class)->approve($transaction, $reviewer);

        $this->assertSame('paid', $invoice->fresh()->status);
        Notification::assertSentTo($student, FeeSubmissionReviewed::class);

        Carbon::setTestNow();
    }

    public function test_overview_and_invoice_resources_expose_installment_data(): void
    {
        Carbon::setTestNow('2026-07-22');

        $student = $this->actingAsStudent();
        $this->plan($student, Carbon::parse('2026-06-01')); // first due Jun 5 → defaulted by Jul 22
        Artisan::call('billing:sweep');

        $this->getJson('/api/v1/billing')->assertOk()
            ->assertJsonPath('data.installments.months', 6)
            ->assertJsonPath('data.installments.due_day', 5)
            ->assertJsonPath('data.installments.grace_days', 5)
            ->assertJsonPath('data.next_invoice.sequence_no', 1);

        $list = $this->getJson('/api/v1/billing/invoices')->assertOk()->json('data');
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($list, 'sequence_no'));
        $this->assertSame('past_due', $list[0]['status']);
        $this->assertGreaterThan(0, $list[0]['fine_amount']);
        $this->assertSame('upcoming', $list[3]['status']);
        $this->assertArrayHasKey('payable_total', $list[0]);

        Carbon::setTestNow();
    }
}
