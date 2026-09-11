<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The transactions export is money, and money needs billing.view.
 *
 * Every other report is ordinary reporting — enrollments, completion,
 * attendance, quiz results — and reports.view covers them. The transactions
 * one is different in kind: it carries student names and emails alongside
 * amounts, payment methods and card last-4 digits, and reports.export is held
 * by roles holding no billing permission at all. A manager could download it.
 *
 * Gated per report rather than at the route, so a report added later has to
 * answer the question rather than inherit whatever the route happened to say.
 */
class ReportVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
    }

    public function test_a_manager_is_not_offered_the_transactions_export(): void
    {
        $page = $this->actingAs($this->manager)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Transactions', $page);
        $this->assertStringNotContainsString('amounts and statuses', $page);
    }

    public function test_a_manager_keeps_every_report_that_is_not_money(): void
    {
        // The point of gating per report: this removes one of five, not the
        // reporting screen. A manager still does their job.
        $page = $this->actingAs($this->manager)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->getContent();

        foreach (['Enrollments', 'Course completion', 'Attendance', 'Quiz results'] as $report) {
            $this->assertStringContainsString($report, $page);
        }
    }

    public function test_a_manager_guessing_the_export_url_gets_403(): void
    {
        // The listing is not the control. 403 rather than 404, because the
        // report exists — it is simply not theirs.
        $this->actingAs($this->manager)
            ->get(route('admin.reports.export', 'transactions'))
            ->assertForbidden();
    }

    public function test_a_manager_can_still_download_the_other_exports(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.reports.export', 'enrollments'))
            ->assertOk();
    }

    public function test_the_manager_is_never_handed_the_transaction_count(): void
    {
        // Reaches past the markup: a row count is a small thing, but it is
        // still a number about money, and the query should not run at all.
        $this->actingAs($this->manager)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertViewHas('reports', fn ($reports) => ! array_key_exists('transactions', $reports))
            ->assertViewHas('counts', fn ($counts) => ! array_key_exists('transactions', $counts));
    }

    public function test_an_admin_sees_and_can_download_transactions(): void
    {
        $page = $this->actingAs($this->admin)
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertViewHas('counts', fn ($counts) => array_key_exists('transactions', $counts))
            ->getContent();

        $this->assertStringContainsString('Transactions', $page);

        $this->actingAs($this->admin)
            ->get(route('admin.reports.export', 'transactions'))
            ->assertOk();
    }

    public function test_an_unknown_report_is_still_a_404(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.export', 'salaries'))
            ->assertNotFound();
    }

    public function test_granting_billing_view_is_what_restores_it(): void
    {
        // Permission, not role name.
        $this->actingAs($this->manager)
            ->get(route('admin.reports.export', 'transactions'))
            ->assertForbidden();

        $this->manager->givePermissionTo('billing.view');

        $this->actingAs($this->manager->fresh())
            ->get(route('admin.reports.export', 'transactions'))
            ->assertOk();
    }
}
