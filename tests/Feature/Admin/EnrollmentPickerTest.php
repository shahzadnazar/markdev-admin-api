<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnrollmentPickerTest extends TestCase
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
            'slug' => 'laravel-mastery-'.Str::random(4),
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now(),
            'is_free' => false,
        ]);
    }

    public function test_picker_lists_only_active_students_with_enroll_button(): void
    {
        $inactive = User::factory()->create(['name' => 'Gone Student', 'is_active' => false]);
        $inactive->assignRole('student');

        $this->actingAs($this->admin)->get('/admin/enrollments/create')
            ->assertOk()
            ->assertSee('Enroll students')
            ->assertSee('Aliya Khan')
            ->assertSee('Enroll now')
            ->assertDontSee('Gone Student');
    }

    public function test_unenrolled_tab_hides_already_enrolled_students(): void
    {
        $enrolled = User::factory()->create(['name' => 'Busy Student']);
        $enrolled->assignRole('student');
        Enrollment::create(['user_id' => $enrolled->id, 'course_id' => $this->course->id, 'enrolled_at' => now()]);

        $this->actingAs($this->admin)->get('/admin/enrollments/create?tab=unenrolled')
            ->assertOk()
            ->assertSee('Aliya Khan')
            ->assertDontSee('Busy Student');
    }

    public function test_course_filter_shows_that_courses_students(): void
    {
        $enrolled = User::factory()->create(['name' => 'Busy Student']);
        $enrolled->assignRole('student');
        Enrollment::create(['user_id' => $enrolled->id, 'course_id' => $this->course->id, 'enrolled_at' => now()]);

        $this->actingAs($this->admin)->get('/admin/enrollments/create?course='.$this->course->id)
            ->assertOk()
            ->assertSee('Busy Student')
            ->assertDontSee('Aliya Khan');
    }

    public function test_enrolling_from_the_popup_redirects_back_to_the_picker(): void
    {
        $this->actingAs($this->admin)->post('/admin/enrollments', [
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
        ])->assertRedirect(route('admin.enrollments.create'));

        $this->assertDatabaseHas('enrollments', [
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
        ]);
    }

    public function test_enrolling_with_plan_creates_monthly_invoices(): void
    {
        $this->actingAs($this->admin)->post('/admin/enrollments', [
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'create_plan' => '1',
            'total_fee' => 60000,
            'months' => 6,
            'due_day' => 5,
            'currency' => 'PKR',
        ]);

        $this->assertDatabaseHas('fee_plans', ['user_id' => $this->student->id, 'course_id' => $this->course->id]);
        $this->assertSame(6, $this->student->invoices()->count());
        $this->assertEquals(60000.0, (float) $this->student->invoices()->sum('amount'));
    }

    public function test_duplicate_enrollment_shows_a_friendly_error(): void
    {
        Enrollment::create(['user_id' => $this->student->id, 'course_id' => $this->course->id, 'enrolled_at' => now()]);

        $this->actingAs($this->admin)->post('/admin/enrollments', [
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
        ])->assertSessionHas('error');

        $this->assertSame(1, Enrollment::count());
    }

    public function test_generating_fee_for_an_already_enrolled_student_creates_the_plan_only(): void
    {
        Enrollment::create(['user_id' => $this->student->id, 'course_id' => $this->course->id, 'enrolled_at' => now()]);

        $this->actingAs($this->admin)->post('/admin/enrollments', [
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'create_plan' => '1',
            'total_fee' => 48000,
            'months' => 6,
            'due_day' => 5,
            'currency' => 'PKR',
        ])->assertRedirect(route('admin.enrollments.create'))
            ->assertSessionHas('success');

        $this->assertSame(1, Enrollment::count()); // no duplicate enrollment
        $this->assertDatabaseHas('fee_plans', ['user_id' => $this->student->id, 'course_id' => $this->course->id]);
        $this->assertSame(6, $this->student->invoices()->count());
    }

    public function test_a_second_in_flight_fee_plan_is_rejected(): void
    {
        Enrollment::create(['user_id' => $this->student->id, 'course_id' => $this->course->id, 'enrolled_at' => now()]);
        $plan = app(\App\Services\InstallmentPlanService::class)->create(
            student: $this->student, course: $this->course, title: $this->course->title,
            totalFee: 48000, months: 6, dueDay: 5,
        );

        $payload = [
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'create_plan' => '1',
            'total_fee' => 48000,
            'months' => 6,
            'due_day' => 5,
            'currency' => 'PKR',
        ];

        $this->actingAs($this->admin)->post('/admin/enrollments', $payload)->assertSessionHas('error');
        $this->assertSame(1, \App\Models\FeePlan::count());

        // A fully paid plan no longer blocks a new one.
        $plan->invoices()->update(['status' => 'paid', 'paid_at' => now()]);

        $this->actingAs($this->admin)->post('/admin/enrollments', $payload)->assertSessionHas('success');
        $this->assertSame(2, \App\Models\FeePlan::count());
    }

    public function test_full_payment_creates_a_single_invoice(): void
    {
        $this->actingAs($this->admin)->post('/admin/enrollments', [
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'create_plan' => '1',
            'payment_mode' => 'full',
            'total_fee' => 48000,
            'months' => 1,
            'due_day' => 5,
            'currency' => 'PKR',
        ])->assertSessionHas('success');

        $this->assertSame(1, $this->student->invoices()->count());
        $this->assertEquals(48000.0, (float) $this->student->invoices()->sum('amount'));
    }

    public function test_re_enrolling_after_removal_restores_the_enrollment(): void
    {
        $enrollment = Enrollment::create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'enrolled_at' => now()->subMonth(),
            'progress_percent' => 40,
        ]);
        $enrollment->delete(); // soft delete — the unique index still holds the row

        $this->actingAs($this->admin)->post('/admin/enrollments', [
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
        ])->assertRedirect(route('admin.enrollments.create'))
            ->assertSessionHas('success');

        $enrollment->refresh();
        $this->assertNull($enrollment->deleted_at);
        $this->assertEquals(0, (float) $enrollment->progress_percent);
        $this->assertTrue($enrollment->enrolled_at->isToday());
        $this->assertSame(1, Enrollment::withTrashed()->count());
    }

    public function test_inactive_students_cannot_be_enrolled(): void
    {
        $this->student->update(['is_active' => false]);

        $this->actingAs($this->admin)->post('/admin/enrollments', [
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
        ])->assertStatus(422);
    }
}
