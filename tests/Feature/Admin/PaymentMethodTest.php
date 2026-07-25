<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\InstallmentPlanService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

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

    protected function makeStudent(): User
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        return $student;
    }

    public function test_admin_can_create_a_method_attached_to_courses(): void
    {
        $this->actingAs($this->admin)->post('/admin/billing/payment-methods', [
            'name' => 'JazzCash — MarkDev',
            'channel' => 'jazzcash',
            'account_title' => 'Mark Dev Solutions',
            'account_number' => '0300-1234567',
            'is_active' => '1',
            'courses' => [$this->course->id],
        ])->assertRedirect(route('admin.billing.payment-methods.index'));

        $method = PaymentMethod::first();
        $this->assertSame('JazzCash — MarkDev', $method->name);
        $this->assertTrue($method->courses->contains($this->course));

        $this->actingAs($this->admin)->get('/admin/billing/payment-methods')
            ->assertOk()
            ->assertSee('JazzCash — MarkDev')
            ->assertSee('0300-1234567');
    }

    public function test_overview_scopes_methods_to_the_plan_course(): void
    {
        $courseMethod = PaymentMethod::create([
            'name' => 'JazzCash — MarkDev', 'channel' => 'jazzcash',
            'account_title' => 'Mark Dev', 'account_number' => '0300-1111111', 'is_active' => true,
        ]);
        $courseMethod->courses()->attach($this->course->id);

        PaymentMethod::create([
            'name' => 'Meezan Bank', 'channel' => 'bank_transfer',
            'account_title' => 'Mark Dev', 'account_number' => 'PK36MEZN0001', 'is_active' => true,
        ]);

        $student = $this->makeStudent();
        Enrollment::create(['user_id' => $student->id, 'course_id' => $this->course->id, 'enrolled_at' => now()]);
        app(InstallmentPlanService::class)->create(
            student: $student, course: $this->course, title: 'Plan',
            totalFee: 48000, months: 6, dueDay: 5, advance: true,
        );

        // Course has an attached method → only that one is offered.
        $data = $this->actingAs($student)->getJson('/api/v1/billing')->assertOk()->json('data');
        $this->assertCount(1, $data['payment_methods']);
        $this->assertSame('JazzCash — MarkDev', $data['payment_methods'][0]['name']);
        $this->assertSame('0300-1111111', $data['payment_methods'][0]['account_number']);

        // A course with no attached methods falls back to the unrestricted ones.
        $other = $this->makeStudent();
        app(InstallmentPlanService::class)->create(
            student: $other, course: null, title: 'Plan B',
            totalFee: 30000, months: 3, dueDay: 5, advance: true,
        );

        $data = $this->actingAs($other)->getJson('/api/v1/billing')->assertOk()->json('data');
        $this->assertCount(1, $data['payment_methods']);
        $this->assertSame('Meezan Bank', $data['payment_methods'][0]['name']);
    }

    public function test_student_submission_records_the_chosen_method(): void
    {
        Storage::fake('public');

        $method = PaymentMethod::create([
            'name' => 'JazzCash — MarkDev', 'channel' => 'jazzcash',
            'account_title' => 'Mark Dev', 'account_number' => '0300-1111111',
            'bank_name' => null, 'is_active' => true,
        ]);

        $student = $this->makeStudent();
        $plan = app(InstallmentPlanService::class)->create(
            student: $student, course: $this->course, title: 'Plan',
            totalFee: 48000, months: 6, dueDay: 5, advance: true,
        );
        $open = $plan->invoices()->where('status', 'open')->orderBy('sequence_no')->first();

        $response = $this->actingAs($student)->post("/api/v1/billing/invoices/{$open->id}/submissions", [
            'payment_method_id' => $method->id,
            'payer_name' => 'Aliya',
            'reference_no' => 'JC-1',
            'payment_date' => now()->toDateString(),
            'receipt' => UploadedFile::fake()->image('slip.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $this->assertDatabaseHas('transactions', [
            'id' => $response->json('data.id'),
            'payment_method_id' => $method->id,
            'method_brand' => 'JazzCash — MarkDev',
            'method_type' => 'wallet',
        ]);
    }

    public function test_inactive_methods_are_rejected_on_submission(): void
    {
        Storage::fake('public');

        $method = PaymentMethod::create([
            'name' => 'Old account', 'channel' => 'jazzcash',
            'account_title' => 'Mark Dev', 'account_number' => '0300-0', 'is_active' => false,
        ]);

        $student = $this->makeStudent();
        $plan = app(InstallmentPlanService::class)->create(
            student: $student, course: $this->course, title: 'Plan',
            totalFee: 48000, months: 6, dueDay: 5, advance: true,
        );
        $open = $plan->invoices()->where('status', 'open')->orderBy('sequence_no')->first();

        $this->actingAs($student)->post("/api/v1/billing/invoices/{$open->id}/submissions", [
            'payment_method_id' => $method->id,
            'payer_name' => 'Aliya',
            'reference_no' => 'JC-1',
            'payment_date' => now()->toDateString(),
            'receipt' => UploadedFile::fake()->image('slip.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
