<?php

namespace Tests\Feature\Admin;

use App\Models\Course;
use App\Models\User;
use App\Services\InstallmentPlanService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FeePlanScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->student = User::factory()->create(['name' => 'Aliya Khan']);
        $this->student->assignRole('student');
    }

    protected function makePlan(): \App\Models\FeePlan
    {
        $course = Course::create([
            'title' => 'Laravel Mastery',
            'slug' => 'laravel-'.Str::random(4),
            'excerpt' => 'x',
            'level' => 'beginner',
            'status' => 'published',
            'published_at' => now(),
            'is_free' => false,
        ]);

        return app(InstallmentPlanService::class)->create(
            student: $this->student,
            course: $course,
            title: $course->title,
            totalFee: 60000,
            months: 6,
            dueDay: 5,
            finePerDay: null,
            currency: 'PKR',
        );
    }

    public function test_plans_index_shows_progress_and_terms(): void
    {
        $this->makePlan();

        $this->actingAs($this->admin)->get('/admin/billing/plans')
            ->assertOk()
            ->assertSee('Fee plans')
            ->assertSee('Aliya Khan')
            ->assertSee('0/6')
            ->assertSee('6 mo · day 5')
            ->assertSee('Outstanding');
    }

    public function test_plan_schedule_page_lists_every_installment(): void
    {
        $plan = $this->makePlan();

        $this->actingAs($this->admin)->get('/admin/billing/plans/'.$plan->id)
            ->assertOk()
            ->assertSee('Installment')
            ->assertSee('1/6')
            ->assertSee('6/6')
            ->assertSee('Rs 10,000')
            ->assertSee('Next due');
    }

    public function test_completed_tab_excludes_unfinished_plans(): void
    {
        $plan = $this->makePlan();

        $this->actingAs($this->admin)->get('/admin/billing/plans?tab=completed')
            ->assertOk()
            ->assertDontSee('Aliya Khan');

        $plan->invoices()->update(['status' => 'paid', 'paid_at' => now()]);

        $this->actingAs($this->admin)->get('/admin/billing/plans?tab=completed')
            ->assertOk()
            ->assertSee('Aliya Khan')
            ->assertSee('completed');
    }

    public function test_defaulters_tab_finds_past_due_plans(): void
    {
        $plan = $this->makePlan();
        $plan->invoices()->orderBy('sequence_no')->first()->update(['status' => 'past_due']);

        $this->actingAs($this->admin)->get('/admin/billing/plans?tab=defaulters')
            ->assertOk()
            ->assertSee('Aliya Khan')
            ->assertSee('defaulter');
    }
}
