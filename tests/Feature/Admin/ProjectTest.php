<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Projects: the code that identifies them, the status that governs them, and
 * the day counts that stop when a project is paused.
 */
class ProjectTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Client $client;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');

        $this->client = Client::create(['name' => 'Zephyrine Quartermain', 'company' => 'Bartleby Ironworks PLC', 'is_active' => true]);

        $member = User::factory()->create();
        $this->team = Team::create(['name' => 'Web', 'team_lead_id' => $member->id, 'is_active' => true]);
        $this->team->members()->sync([$member->id]);
    }

    /* ------------------------------- Helpers ------------------------------- */

    protected function statusFor(string $behaviour): ProjectStatus
    {
        return ProjectStatus::where('behaviour', $behaviour)->active()->firstOrFail();
    }

    /** @param  array<string, mixed>  $overrides */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Website Redesign',
            'code' => 'PRJ-014',
            'client_id' => $this->client->id,
            'team_id' => $this->team->id,
            'project_status_id' => $this->statusFor('running')->id,
            'start_date' => today()->subDays(5)->toDateString(),
            'due_date' => today()->addDays(10)->toDateString(),
            'contract_value' => '987654.32',
            'currency' => 'PKR',
            'description' => 'A rebuild.',
        ], $overrides);
    }

    protected function create(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post(route('admin.projects.store'), $this->payload($overrides));
    }

    /* -------------------------------- Codes -------------------------------- */

    public function test_an_admin_creates_a_project(): void
    {
        $this->create()->assertSessionHasNoErrors();

        $project = Project::firstOrFail();

        $this->assertSame('PRJ-014', $project->code);
        $this->assertSame('prj-014', $project->code_key);
        $this->assertSame($this->client->id, $project->client_id);
        $this->assertSame('PKR', $project->currency);
        $this->assertSame('987654.32', (string) $project->contract_value);
    }

    public function test_two_projects_may_share_a_name(): void
    {
        $other = Client::create(['name' => 'Another Client', 'is_active' => true]);

        $this->create()->assertSessionHasNoErrors();
        $this->create(['code' => 'PRJ-015', 'client_id' => $other->id])->assertSessionHasNoErrors();

        $this->assertSame(2, Project::where('name', 'Website Redesign')->count());
    }

    public function test_a_code_collides_across_case_and_spacing(): void
    {
        $this->create()->assertSessionHasNoErrors();

        $this->create(['code' => ' prj-014 '])->assertSessionHasErrors('code');
        $this->create(['code' => 'PRJ-014'])->assertSessionHasErrors('code');

        $this->assertSame(1, Project::count());
    }

    public function test_a_soft_deleted_projects_code_is_free_again(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $project = Project::firstOrFail();

        $this->actingAs($this->admin)->delete(route('admin.projects.destroy', $project))
            ->assertSessionHasNoErrors();

        // Cleared on delete, which is what releases the code. An index on
        // `code` alone would have reserved PRJ-014 for ever.
        $this->assertNull(Project::withTrashed()->find($project->id)->code_key);

        $this->create(['code' => 'PRJ-014'])->assertSessionHasNoErrors();

        $this->assertSame(1, Project::count());
        $this->assertSame(2, Project::withTrashed()->count());
    }

    public function test_the_unique_index_is_the_race_backstop(): void
    {
        $this->create()->assertSessionHasNoErrors();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('projects')->insert([
            'name' => 'Another', 'code' => 'prj-014', 'code_key' => Project::normaliseKey('prj-014'),
            'client_id' => $this->client->id, 'team_id' => $this->team->id,
            'project_status_id' => $this->statusFor('running')->id,
            'currency' => 'PKR', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /* ------------------------------- Statuses ------------------------------- */

    public function test_a_project_cannot_be_created_on_a_retired_status(): void
    {
        $retired = $this->statusFor('planning');
        $retired->update(['is_active' => false]);

        $this->create(['project_status_id' => $retired->id])
            ->assertSessionHasErrors('project_status_id');

        $this->assertSame(0, Project::count());
        $this->assertStringContainsString('on offer', session('errors')->first('project_status_id'));
    }

    public function test_a_retired_status_is_not_offered_on_the_form(): void
    {
        $retired = $this->statusFor('paused');
        $retired->update(['is_active' => false]);

        $this->actingAs($this->admin)->get(route('admin.projects.create'))
            ->assertOk()
            ->assertDontSee('value="'.$retired->id.'"', false);
    }

    public function test_a_status_in_use_can_no_longer_be_deleted(): void
    {
        $status = $this->statusFor('running');
        $this->create()->assertSessionHasNoErrors();

        // usageCount() went live with this phase, which is what turns
        // WorkflowStatusController's refusal from a seam into a refusal.
        $this->assertSame(1, $status->usageCount());

        $this->actingAs($this->admin)
            ->delete(route('admin.project-statuses.destroy', $status))
            ->assertSessionHasErrors('behaviour');

        $this->assertStringContainsString('1 record(s)', session('errors')->first('behaviour'));
        $this->assertDatabaseHas('project_statuses', ['id' => $status->id]);
    }

    /* --------------------------------- Days --------------------------------- */

    public function test_a_running_project_counts_down(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $project = Project::firstOrFail();

        $this->assertSame(10, $project->daysRemaining());
        $this->assertSame(5, $project->elapsedDays());

        $this->actingAs($this->admin)->get(route('admin.projects.index'))
            ->assertOk()->assertSee('10 days left');
    }

    public function test_a_paused_project_is_excluded_from_every_day_figure(): void
    {
        $this->create(['project_status_id' => $this->statusFor('paused')->id])
            ->assertSessionHasNoErrors();

        $project = Project::firstOrFail();

        // A team told to stop is not spending its schedule, so there is no
        // number — not a stale one, and not a zero.
        $this->assertNull($project->daysRemaining());
        $this->assertNull($project->elapsedDays());
        $this->assertTrue($project->isPaused());
        $this->assertFalse($project->isCountingDays());

        $this->actingAs($this->admin)->get(route('admin.projects.index'))
            ->assertOk()
            ->assertDontSee('10 days left')
            ->assertDontSee('days over')
            ->assertSee('Paused');

        $this->actingAs($this->admin)->get(route('admin.projects.show', $project))
            ->assertOk()
            ->assertDontSee('10 days left');

        // And it is out of the scope anything aggregating days would use.
        $this->assertSame(0, Project::countingDays()->count());
    }

    public function test_a_closed_project_has_no_countdown_either(): void
    {
        $this->create(['project_status_id' => $this->statusFor('closed_success')->id])
            ->assertSessionHasNoErrors();

        $this->assertNull(Project::firstOrFail()->daysRemaining());
    }

    public function test_an_overdue_running_project_counts_up(): void
    {
        $this->create(['due_date' => today()->subDays(3)->toDateString()])
            ->assertSessionHasNoErrors();

        $this->assertSame(-3, Project::firstOrFail()->daysRemaining());

        $this->actingAs($this->admin)->get(route('admin.projects.index'))
            ->assertOk()->assertSee('3 days over');
    }

    /**
     * The behaviour decides, never the label.
     *
     * Renaming "On Hold" to anything at all must not start the clock again —
     * that rename is exactly the trap the behaviour column exists to avoid.
     */
    public function test_renaming_the_paused_status_does_not_restart_the_clock(): void
    {
        $paused = $this->statusFor('paused');
        $this->create(['project_status_id' => $paused->id])->assertSessionHasNoErrors();

        $paused->update(['label' => 'Waiting on client']);

        $this->assertNull(Project::firstOrFail()->fresh('status')->daysRemaining());
    }

    /* -------------------------------- Audit --------------------------------- */

    public function test_changing_the_contract_value_is_audited_with_both_figures_and_who(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $project = Project::firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('admin.projects.update', $project), $this->payload(['contract_value' => '1250000.00']))
            ->assertSessionHasNoErrors();

        $entry = AuditLog::where('module', 'projects')
            ->where('action', 'updated')
            ->where('record_id', $project->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('987654.32', (string) $entry->old_values['contract_value']);
        $this->assertSame('1250000.00', (string) $entry->new_values['contract_value']);
        $this->assertSame($this->admin->id, $entry->user_id);
        $this->assertSame($this->admin->name, $entry->user_name);
    }

    /* ------------------------------ Milestones ------------------------------ */

    public function test_milestones_are_added_ordered_and_completed_on_a_day(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $project = Project::firstOrFail();

        foreach (['Design sign-off', 'Build complete'] as $name) {
            $this->actingAs($this->admin)
                ->post(route('admin.projects.milestones.store', $project), [
                    'name' => $name,
                    'due_date' => today()->addDays(7)->toDateString(),
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(['Design sign-off', 'Build complete'], $project->milestones()->pluck('name')->all());

        $first = $project->milestones()->firstOrFail();

        // Defaults to not shared: the safe reading of a milestone nobody
        // marked, and the client portal is phase 6.
        $this->assertFalse($first->is_client_visible);
        $this->assertFalse($first->isComplete());

        $this->actingAs($this->admin)
            ->put(route('admin.projects.milestones.update', [$project, $first]), [
                'name' => $first->name,
                'completed_on' => today()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($first->fresh()->isComplete());
        $this->assertSame(today()->toDateString(), $first->fresh()->completed_on->toDateString());
    }

    public function test_a_milestone_from_another_project_is_not_found(): void
    {
        $this->create()->assertSessionHasNoErrors();
        $this->create(['code' => 'PRJ-015'])->assertSessionHasNoErrors();

        [$one, $two] = Project::orderBy('id')->get()->all();

        $milestone = $two->milestones()->create(['name' => 'Theirs', 'sort_order' => 1]);

        $this->actingAs($this->admin)
            ->get(route('admin.projects.milestones.edit', [$one, $milestone]))
            ->assertNotFound();
    }
}
