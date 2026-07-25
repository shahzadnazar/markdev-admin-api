<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\FeePlan;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\InstallmentPlanService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdmissionBillingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $student;

    protected Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->student = User::factory()->create(['name' => 'Aliya Khan']);
        $this->student->assignRole('student');

        $this->course = Course::create([
            'title' => 'Laravel Mastery',
            'slug' => 'laravel-'.Str::random(4),
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now(),
            'is_free' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admission_creates_registration_fee_and_advance_first_installment(): void
    {
        Carbon::setTestNow('2026-07-25');

        $this->actingAs($this->admin)->post('/admin/enrollments', [
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'create_plan' => '1',
            'registration_fee' => 2000,
            'total_fee' => 48000,
            'months' => 6,
            'due_day' => 5,
            'currency' => 'PKR',
        ])->assertSessionHas('success');

        $invoices = Invoice::orderByRaw('sequence_no is null desc')->orderBy('sequence_no')->get();
        $this->assertCount(7, $invoices); // registration + 6 installments

        $registration = $invoices->firstWhere('type', 'registration');
        $this->assertSame('2026-07-25', $registration->due_at->toDateString());
        $this->assertSame('open', $registration->status);
        $this->assertEquals(2000.0, (float) $registration->amount);

        $first = $invoices->firstWhere('sequence_no', 1);
        $this->assertSame('2026-07-25', $first->due_at->toDateString()); // advance — due at admission
        $this->assertSame('open', $first->status);
        $this->assertEquals(8000.0, (float) $first->amount);
        $this->assertStringContainsString('advance', $first->title);

        $this->assertSame('2026-08-05', $invoices->firstWhere('sequence_no', 2)->due_at->toDateString());
        $this->assertSame('2026-12-05', $invoices->firstWhere('sequence_no', 6)->due_at->toDateString());
        $this->assertEquals(50000.0, (float) $invoices->sum('amount'));

        // Plan total stays tuition-only.
        $this->assertEquals(48000.0, (float) FeePlan::first()->total_amount);
    }

    public function test_custom_first_installment_divides_the_remainder_equally(): void
    {
        Carbon::setTestNow('2026-07-25');

        $this->actingAs($this->admin)->post('/admin/enrollments', [
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'create_plan' => '1',
            'registration_fee' => 0,
            'first_amount' => 10000,
            'total_fee' => 48000,
            'months' => 6,
            'due_day' => 5,
            'currency' => 'PKR',
        ])->assertSessionHas('success');

        $amounts = Invoice::orderBy('sequence_no')->pluck('amount')->map(fn ($a) => (float) $a)->all();
        $this->assertSame([10000.0, 7600.0, 7600.0, 7600.0, 7600.0, 7600.0], $amounts);
        $this->assertSame(0, Invoice::where('type', 'registration')->count()); // waived
    }

    public function test_admission_on_the_due_day_does_not_double_book_the_month(): void
    {
        Carbon::setTestNow('2026-07-05');

        app(InstallmentPlanService::class)->create(
            student: $this->student, course: $this->course, title: 'Plan',
            totalFee: 48000, months: 6, dueDay: 5, advance: true,
        );

        $due = Invoice::orderBy('sequence_no')->pluck('due_at')->map(fn ($d) => $d->toDateString())->all();
        $this->assertSame('2026-07-05', $due[0]);
        $this->assertSame('2026-08-05', $due[1]);
    }

    public function test_adjusting_an_installment_spreads_the_difference(): void
    {
        Carbon::setTestNow('2026-07-25');

        $plan = app(InstallmentPlanService::class)->create(
            student: $this->student, course: $this->course, title: 'Plan',
            totalFee: 48000, months: 6, dueDay: 5, advance: true,
        );

        $second = $plan->invoices()->where('sequence_no', 2)->first();

        $this->actingAs($this->admin)->post("/admin/billing/invoices/{$second->id}/adjust", [
            'amount' => 5000,
            'rebalance' => '1',
        ])->assertSessionHas('success');

        $amounts = $plan->invoices()->orderBy('sequence_no')->pluck('amount')->map(fn ($a) => (float) $a)->all();
        $this->assertSame([8000.0, 5000.0, 8750.0, 8750.0, 8750.0, 8750.0], $amounts);
        $this->assertEquals(48000.0, (float) $plan->fresh()->total_amount);
    }

    public function test_paid_invoices_cannot_be_adjusted(): void
    {
        $plan = app(InstallmentPlanService::class)->create(
            student: $this->student, course: $this->course, title: 'Plan',
            totalFee: 48000, months: 6, dueDay: 5, advance: true,
        );

        $first = $plan->invoices()->where('sequence_no', 1)->first();
        $first->update(['status' => 'paid', 'paid_at' => now()]);

        $this->actingAs($this->admin)->post("/admin/billing/invoices/{$first->id}/adjust", [
            'amount' => 5000,
        ])->assertSessionHas('error');

        $this->assertEquals(8000.0, (float) $first->fresh()->amount);
    }

    public function test_plan_page_shows_registration_row_and_installment_only_progress(): void
    {
        Carbon::setTestNow('2026-07-25');

        $this->actingAs($this->admin)->post('/admin/enrollments', [
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'create_plan' => '1',
            'registration_fee' => 2000,
            'total_fee' => 48000,
            'months' => 6,
            'due_day' => 5,
            'currency' => 'PKR',
        ]);

        $plan = FeePlan::first();

        $this->actingAs($this->admin)->get('/admin/billing/plans/'.$plan->id)
            ->assertOk()
            ->assertSee('REG')
            ->assertSee('Registration fee')
            ->assertSee('0/6')       // registration row excluded from progress
            ->assertSee('due today')
            ->assertSee('Payable now');
    }

    public function test_overview_exposes_the_admission_block_until_settled(): void
    {
        Carbon::setTestNow('2026-07-25');

        $this->actingAs($this->admin)->post('/admin/enrollments', [
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'create_plan' => '1',
            'registration_fee' => 2000,
            'total_fee' => 48000,
            'months' => 6,
            'due_day' => 5,
            'currency' => 'PKR',
        ]);

        $data = $this->actingAs($this->student)->getJson('/api/v1/billing')->assertOk()->json('data');

        $this->assertNotNull($data['admission']);
        $this->assertCount(2, $data['admission']['invoices']);
        $this->assertSame('registration', $data['admission']['invoices'][0]['type']);
        $this->assertEquals(10000.0, $data['admission']['total_due']);

        // Once both admission invoices are paid the block disappears.
        Invoice::whereDate('due_at', '2026-07-25')->update(['status' => 'paid', 'paid_at' => now()]);

        $data = $this->actingAs($this->student)->getJson('/api/v1/billing')->assertOk()->json('data');
        $this->assertNull($data['admission']);
    }

    public function test_submission_works_with_only_method_and_receipt(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $method = PaymentMethod::create([
            'name' => 'JazzCash — MarkDev', 'channel' => 'jazzcash',
            'account_title' => 'Mark Dev', 'account_number' => '0300-1111111', 'is_active' => true,
        ]);

        $plan = app(InstallmentPlanService::class)->create(
            student: $this->student, course: $this->course, title: 'Plan',
            totalFee: 48000, months: 6, dueDay: 5, advance: true,
        );
        $open = $plan->invoices()->where('status', 'open')->orderBy('sequence_no')->first();

        $response = $this->actingAs($this->student)->post("/api/v1/billing/invoices/{$open->id}/submissions", [
            'payment_method_id' => $method->id,
            'receipt' => \Illuminate\Http\UploadedFile::fake()->image('slip.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        // Payer name and payment date fall back to sensible defaults.
        $this->assertDatabaseHas('transactions', [
            'id' => $response->json('data.id'),
            'payer_name' => $this->student->name,
            'reference_no' => null,
        ]);
    }

    public function test_registration_fee_setting_is_saved_and_used_as_default(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $this->actingAs($superAdmin)->put('/admin/settings', [
            'site_name' => 'MarkDev',
            'registration_fee' => 3500,
            'defaulter_fine_per_day' => 100,
            'billing_grace_days' => 5,
            'billing_activation_days' => 5,
            'timezone' => 'Asia/Karachi',
            'attendance_day_start' => '09:00',
            'attendance_late_after_minutes' => 15,
        ])->assertSessionHas('success');

        $this->assertEquals(3500.0, \App\Support\BillingConfig::registrationFee());
    }
}
