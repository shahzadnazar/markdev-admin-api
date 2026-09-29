<?php

namespace Tests\Feature\Admin;

use App\Models\Task;
use App\Models\TaskStatusPeriod;
use App\Models\Team;
use App\Models\User;
use App\Services\StintClock;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * Tasks: splitting, the project-team rule, handovers, reopens and blocking.
 */
class TaskTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected User $admin;

    protected User $lead;

    protected User $member;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();

        $this->admin = $this->roleUser('super-admin');
        $this->lead = $this->roleUser('team-lead', ['name' => 'Lead Person']);
        $this->member = $this->roleUser('team', ['name' => 'Member Person']);
        $this->team = $this->makeTeam('Web', $this->lead, [$this->lead, $this->member]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $overrides */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Rebuild the checkout',
            'team_id' => $this->team->id,
            'task_status_id' => $this->taskStatus('active')->id,
            'days_allowed' => 5,
        ], $overrides);
    }

    protected function create(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.tasks.store'), $this->payload($overrides));
    }

    /* ------------------------------ Splitting ------------------------------- */

    public function test_a_task_can_be_split_into_parts(): void
    {
        $parent = $this->makeTask($this->team, ['days_allowed' => 4]);

        $this->create(['title' => 'Part one', 'parent_id' => $parent->id, 'days_allowed' => 3])->assertSessionHasNoErrors();
        $this->create(['title' => 'Part two', 'parent_id' => $parent->id, 'days_allowed' => 3])->assertSessionHasNoErrors();

        $this->assertSame(2, $parent->parts()->count());
    }

    public function test_a_part_cannot_be_split_again(): void
    {
        $parent = $this->makeTask($this->team);
        $part = $this->makeTask($this->team, ['title' => 'Part one', 'parent_id' => $parent->id]);

        $this->create(['title' => 'Sub-part', 'parent_id' => $part->id])
            ->assertSessionHasErrors('parent_id');

        // And not through the model either: a three-level tree has no answer
        // to whose days a delay is against, so the rule lives on `saving`.
        $this->expectException(ValidationException::class);

        Task::create($this->payload(['title' => 'Straight past the form', 'parent_id' => $part->id]));
    }

    public function test_a_task_with_parts_cannot_itself_become_a_part(): void
    {
        $parent = $this->makeTask($this->team);
        $this->makeTask($this->team, ['title' => 'Part one', 'parent_id' => $parent->id]);
        $other = $this->makeTask($this->team, ['title' => 'Somewhere else']);

        $this->expectException(ValidationException::class);

        $parent->update(['parent_id' => $other->id]);
    }

    /**
     * Parts may total more or less than the parent, and the gap is shown.
     *
     * Nothing blocks it and nothing warns on submit: that gap is the LEAD's
     * planning signal, and it must never reach a member's score.
     */
    public function test_parts_totalling_more_than_the_parent_are_accepted_and_reported(): void
    {
        $parent = $this->makeTask($this->team, ['days_allowed' => 4]);

        $this->create(['title' => 'Part one', 'parent_id' => $parent->id, 'days_allowed' => 3])->assertSessionHasNoErrors();
        $this->create(['title' => 'Part two', 'parent_id' => $parent->id, 'days_allowed' => 3])->assertSessionHasNoErrors();

        $summary = $parent->fresh()->splitSummary();

        $this->assertSame(['parts' => 2, 'allowed' => 6, 'promised' => 4, 'matches' => false], $summary);

        $this->actingAs($this->admin)->get(route('admin.tasks.show', $parent))
            ->assertOk()
            ->assertSee('Split into 2 parts totalling 6 days against 4 allowed.');
    }

    /* --------------------------- Project and team --------------------------- */

    public function test_a_task_on_another_teams_project_is_refused_on_the_form(): void
    {
        $other = $this->makeTeam('Graphics');
        $project = $this->makeProject($other);

        $this->create(['project_id' => $project->id])->assertSessionHasErrors('project_id');

        $this->assertSame(0, Task::count());
    }

    public function test_a_task_on_another_teams_project_is_refused_on_save(): void
    {
        $other = $this->makeTeam('Graphics');
        $project = $this->makeProject($other);

        // The same rule, straight past the form: a hand-posted id, a seeder or
        // a later screen meets it too.
        $this->expectException(ValidationException::class);

        Task::create($this->payload(['project_id' => $project->id]));
    }

    public function test_a_task_on_its_own_teams_project_is_accepted(): void
    {
        $project = $this->makeProject($this->team);

        $this->create(['project_id' => $project->id])->assertSessionHasNoErrors();

        $this->assertSame($project->id, Task::firstOrFail()->project_id);
    }

    public function test_internal_work_has_no_project(): void
    {
        $this->create()->assertSessionHasNoErrors();

        $this->assertNull(Task::firstOrFail()->project_id);
    }

    /* -------------------------------- Status -------------------------------- */

    public function test_a_task_cannot_be_created_on_a_retired_status(): void
    {
        $retired = $this->taskStatus('open');
        $live = $this->taskStatus('active')->id;
        $retired->update(['is_active' => false]);

        $this->create(['task_status_id' => $retired->id])
            ->assertSessionHasErrors('task_status_id');

        // And the live one still works, so the refusal is about being retired
        // rather than about the form being broken.
        $this->create(['task_status_id' => $live])->assertSessionHasNoErrors();
    }

    public function test_moving_to_a_blocked_status_requires_a_reason(): void
    {
        $task = $this->makeTask($this->team);
        // The member has to hold a stint on it, or the task is not theirs to
        // see at all and the answer is a 404 before any of this.
        $this->makeStint($task, $this->member, 5, $this->monday());
        $blocked = $this->taskStatus('blocked');

        $this->actingAs($this->member)
            ->post(route('admin.tasks.move', $task), ['task_status_id' => $blocked->id])
            ->assertSessionHasErrors('reason');

        $this->assertSame($this->taskStatus('active')->id, $task->fresh()->task_status_id);
    }

    public function test_a_member_may_block_their_own_task_with_a_reason(): void
    {
        $task = $this->makeTask($this->team);
        $this->makeStint($task, $this->member, 5, $this->monday());
        $blocked = $this->taskStatus('blocked');

        $this->actingAs($this->member)
            ->post(route('admin.tasks.move', $task), [
                'task_status_id' => $blocked->id,
                'reason' => 'Waiting on the client to send the copy',
            ])
            ->assertSessionHasNoErrors();

        $period = TaskStatusPeriod::where('task_id', $task->id)->where('task_status_id', $blocked->id)->firstOrFail();

        // Stored and attributed: parking stops a clock, so it is a decision
        // with a name on it.
        $this->assertSame('Waiting on the client to send the copy', $period->reason);
        $this->assertSame($this->member->id, $period->changed_by);
        $this->assertDatabaseHas('audit_logs', ['module' => 'task_status_periods', 'action' => 'created']);
    }

    /* ------------------------------- Handover ------------------------------- */

    public function test_a_handover_closes_one_stint_and_opens_another(): void
    {
        $task = $this->makeTask($this->team, ['days_allowed' => 10]);

        $this->actingAs($this->lead)->post(route('admin.tasks.assign.store', $task), [
            'user_id' => $this->member->id, 'days_allowed' => 4,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->lead)->post(route('admin.tasks.assign.store', $task), [
            'user_id' => $this->lead->id, 'days_allowed' => 7,
        ])->assertSessionHasNoErrors();

        $stints = $task->assignments()->orderBy('id')->get();

        $this->assertCount(2, $stints);

        // Neither on time nor late — and the days are still there to see.
        $this->assertSame('handed_over', $stints[0]->outcome);
        $this->assertNotNull($stints[0]->ended_on);
        $this->assertSame(4, $stints[0]->days_allowed);

        // The new promise is the one the lead typed, NOT what was left over.
        $this->assertSame(7, $stints[1]->days_allowed);
        $this->assertNull($stints[1]->outcome);
        $this->assertSame($this->lead->id, $stints[1]->created_by);
    }

    public function test_a_stint_cannot_be_opened_with_no_days(): void
    {
        $task = $this->makeTask($this->team);

        $this->actingAs($this->lead)->post(route('admin.tasks.assign.store', $task), [
            'user_id' => $this->member->id, 'days_allowed' => 0,
        ])->assertSessionHasErrors('days_allowed');
    }

    public function test_a_task_cannot_be_handed_to_someone_off_the_team(): void
    {
        $task = $this->makeTask($this->team);
        $outsider = $this->roleUser('team');

        $this->actingAs($this->lead)->post(route('admin.tasks.assign.store', $task), [
            'user_id' => $outsider->id, 'days_allowed' => 3,
        ])->assertSessionHasErrors('user_id');
    }

    /* -------------------------------- Reopen -------------------------------- */

    public function test_a_reopen_leaves_the_finished_stint_exactly_as_it_was(): void
    {
        $task = $this->makeTask($this->team);

        $this->actingAs($this->lead)->post(route('admin.tasks.assign.store', $task), [
            'user_id' => $this->member->id, 'days_allowed' => 1,
        ])->assertSessionHasNoErrors();

        // Four working days later, finish it: one day allowed, four taken.
        Carbon::setTestNow($this->monday()->copy()->addDays(4));

        $this->actingAs($this->member)->post(route('admin.tasks.move', $task), [
            'task_status_id' => $this->taskStatus('done')->id,
        ])->assertSessionHasNoErrors();

        $first = $task->assignments()->orderBy('id')->first();
        $this->assertSame('late', $first->outcome);
        $endedOn = $first->ended_on->toDateString();

        // Now reopen it.
        $this->actingAs($this->member)->post(route('admin.tasks.move', $task), [
            'task_status_id' => $this->taskStatus('active')->id,
        ])->assertSessionHasNoErrors();

        $stints = $task->assignments()->orderBy('id')->get();

        $this->assertCount(2, $stints);

        // NOTHING about the first changed. The history is the lock: a late
        // delivery that was reopened and fixed still reads as one late stint
        // and one more stint, which is what happened.
        $this->assertSame('late', $stints[0]->outcome);
        $this->assertSame($endedOn, $stints[0]->ended_on->toDateString());

        $this->assertNull($stints[1]->outcome);
        $this->assertSame($this->member->id, $stints[1]->user_id);
    }

    public function test_finishing_a_task_closes_its_stint_on_its_own_allowance(): void
    {
        // The parent promises twenty days; this person was given one.
        $task = $this->makeTask($this->team, ['days_allowed' => 20]);

        $this->actingAs($this->lead)->post(route('admin.tasks.assign.store', $task), [
            'user_id' => $this->member->id, 'days_allowed' => 1,
        ])->assertSessionHasNoErrors();

        Carbon::setTestNow($this->monday()->copy()->addDays(4));

        $this->actingAs($this->member)->post(route('admin.tasks.move', $task), [
            'task_status_id' => $this->taskStatus('done')->id,
        ])->assertSessionHasNoErrors();

        $stint = $task->assignments()->orderBy('id')->first();

        // Late, on one day. Judging them against the parent's twenty would
        // have called it early.
        $this->assertSame('late', $stint->outcome);
        // Monday to Friday inclusive: five working days against one allowed.
        $this->assertSame(5, app(StintClock::class)->daysTaken($stint->fresh()));
    }

    /* ------------------------------- Statuses ------------------------------- */

    public function test_a_task_status_in_use_can_no_longer_be_deleted(): void
    {
        $status = $this->taskStatus('active');
        $this->makeTask($this->team, ['task_status_id' => $status->id]);

        $this->assertGreaterThan(0, $status->usageCount());

        $this->actingAs($this->admin)
            ->delete(route('admin.task-statuses.destroy', $status))
            ->assertSessionHasErrors('behaviour');

        $this->assertDatabaseHas('task_statuses', ['id' => $status->id]);
    }

    public function test_a_new_task_opens_a_status_period(): void
    {
        $this->create()->assertSessionHasNoErrors();

        $task = Task::firstOrFail();

        $this->assertSame(1, $task->statusPeriods()->count());
        $this->assertNull($task->statusPeriods()->first()->ended_on);
    }
}
