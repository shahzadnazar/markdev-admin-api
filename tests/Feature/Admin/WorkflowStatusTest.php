<?php

namespace Tests\Feature\Admin;

use App\Models\ProjectStatus;
use App\Models\TaskStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The configurable status lists, and the two rules that keep them honest.
 *
 * LABEL IS WORDING, BEHAVIOUR IS MEANING. An admin may rename anything; they
 * may never invent a behaviour, and they may never leave a behaviour with no
 * active status — later phases branch on the behaviour, and a behaviour nothing
 * can reach is a question with no possible answer.
 */
class WorkflowStatusTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    /** @param  array<string, mixed>  $overrides */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Waiting on client',
            'behaviour' => 'blocked',
            'colour' => '#9A6400',
            'is_active' => 1,
        ], $overrides);
    }

    /* ------------------------------- Defaults ------------------------------ */

    public function test_the_migration_seeds_the_default_statuses(): void
    {
        $this->assertSame([
            'To Do' => 'open',
            'In Progress' => 'active',
            'Blocked' => 'blocked',
            'Review' => 'active',
            'Completed' => 'done',
        ], TaskStatus::ordered()->pluck('behaviour', 'label')->all());

        $this->assertSame([
            'Planning' => 'planning',
            'Active' => 'running',
            'On Hold' => 'paused',
            'Completed' => 'closed_success',
            'Cancelled' => 'closed_abandoned',
        ], ProjectStatus::ordered()->pluck('behaviour', 'label')->all());

        // Every behaviour in both sets is reachable out of the box.
        $this->assertSame([], TaskStatus::behavioursWithoutActive());
        $this->assertSame([], ProjectStatus::behavioursWithoutActive());
    }

    /* -------------------------------- Adding ------------------------------- */

    public function test_an_admin_adds_a_status_with_an_existing_behaviour(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.task-statuses.store'), $this->payload())
            ->assertRedirect(route('admin.task-statuses.index'))
            ->assertSessionHasNoErrors();

        $status = TaskStatus::where('label', 'Waiting on client')->firstOrFail();

        $this->assertSame('blocked', $status->behaviour);
        $this->assertTrue($status->is_active);
        // Last in the list, and on offer: usable the moment it is saved.
        $this->assertSame(6, $status->sort_order);
        $this->assertTrue(TaskStatus::active()->where('behaviour', 'blocked')->count() === 2);

        $this->actingAs($this->admin)->get(route('admin.task-statuses.index'))
            ->assertOk()
            ->assertSee('Waiting on client');
    }

    public function test_a_behaviour_outside_the_fixed_set_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.task-statuses.store'), $this->payload(['behaviour' => 'waiting']))
            ->assertSessionHasErrors('behaviour');

        $this->assertSame(5, TaskStatus::count());
    }

    public function test_renaming_a_status_does_not_touch_its_behaviour(): void
    {
        $blocked = TaskStatus::where('behaviour', 'blocked')->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('admin.task-statuses.update', $blocked), $this->payload([
                'label' => 'Waiting on client',
                'behaviour' => 'blocked',
            ]))
            ->assertSessionHasNoErrors();

        $blocked->refresh();

        $this->assertSame('Waiting on client', $blocked->label);
        // The whole point: code asking for `blocked` still finds it.
        $this->assertSame('blocked', $blocked->behaviour);
        $this->assertTrue(TaskStatus::active()->where('behaviour', 'blocked')->exists());
    }

    /* ----------------- Every behaviour keeps one active status -------------- */

    public function test_deleting_the_last_active_done_status_is_refused(): void
    {
        $done = TaskStatus::where('behaviour', 'done')->firstOrFail();

        $this->actingAs($this->admin)
            ->delete(route('admin.task-statuses.destroy', $done))
            ->assertSessionHasErrors('behaviour');

        $this->assertStringContainsString('Done', session('errors')->first('behaviour'));
        // The transaction rolled the delete back, not just reported it.
        $this->assertSame(5, TaskStatus::count());
        $this->assertDatabaseHas('task_statuses', ['id' => $done->id]);
    }

    public function test_retiring_the_last_active_done_status_is_refused(): void
    {
        $done = TaskStatus::where('behaviour', 'done')->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.task-statuses.toggle', $done))
            ->assertSessionHasErrors('behaviour');

        $this->assertTrue($done->fresh()->is_active);
    }

    public function test_moving_the_last_active_done_status_to_another_behaviour_is_refused(): void
    {
        $done = TaskStatus::where('behaviour', 'done')->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('admin.task-statuses.update', $done), $this->payload([
                'label' => $done->label,
                'behaviour' => 'open',
            ]))
            ->assertSessionHasErrors('behaviour');

        $this->assertSame('done', $done->fresh()->behaviour);
    }

    public function test_a_done_status_may_be_deleted_once_another_one_covers_it(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.task-statuses.store'), $this->payload(['label' => 'Shipped', 'behaviour' => 'done']))
            ->assertSessionHasNoErrors();

        $original = TaskStatus::where('label', 'Completed')->firstOrFail();

        $this->actingAs($this->admin)
            ->delete(route('admin.task-statuses.destroy', $original))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('task_statuses', ['id' => $original->id]);
        $this->assertTrue(TaskStatus::active()->where('behaviour', 'done')->exists());
    }

    public function test_the_rule_covers_project_statuses_too(): void
    {
        $abandoned = ProjectStatus::where('behaviour', 'closed_abandoned')->firstOrFail();

        $this->actingAs($this->admin)
            ->delete(route('admin.project-statuses.destroy', $abandoned))
            ->assertSessionHasErrors('behaviour');

        $this->assertStringContainsString('abandoned', session('errors')->first('behaviour'));
        $this->assertDatabaseHas('project_statuses', ['id' => $abandoned->id]);
    }

    /* ------------------------------- Screens ------------------------------- */

    public function test_both_status_screens_render_with_their_behaviours(): void
    {
        foreach (['task-statuses' => TaskStatus::class, 'project-statuses' => ProjectStatus::class] as $route => $model) {
            $response = $this->actingAs($this->admin)->get(route('admin.'.$route.'.index'))->assertOk();

            foreach ($model::BEHAVIOURS as $behaviour => $label) {
                // The behaviour list is on the page, so an admin can see what
                // they are picking between rather than guessing from a label.
                $response->assertSee($behaviour);
            }

            $this->actingAs($this->admin)->get(route('admin.'.$route.'.create'))
                ->assertOk()->assertSee('Behaviour');

            $first = $model::ordered()->first();
            $this->actingAs($this->admin)->get(route('admin.'.$route.'.edit', $first))
                ->assertOk()->assertSee($first->label);
        }
    }

    /* ------------------------------ Reordering ----------------------------- */

    public function test_a_status_can_be_reordered(): void
    {
        $second = TaskStatus::ordered()->skip(1)->first();

        $this->actingAs($this->admin)
            ->post(route('admin.task-statuses.move', $second), ['direction' => 'up']);

        $this->assertSame($second->id, TaskStatus::ordered()->first()->id);
    }
}
