<?php

namespace Tests\Feature\Admin;

use App\Models\Holiday;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Support\TeamCalendar;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * The month view: derived from five sources, narrowed by the existing scopes.
 *
 * Nothing is written anywhere as a calendar row, so there is nothing to keep in
 * sync and nothing here that tests a sync. What these tests watch instead is
 * that the TOGGLE can only narrow and never widen, and that the cost does not
 * grow with the number of days.
 */
class TeamCalendarTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected User $admin;

    protected User $lead;

    protected User $member;

    protected User $outsideLead;

    protected Team $team;

    protected Project $project;

    protected Project $theirProject;

    protected Task $theirs;

    protected Task $mine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();

        $this->admin = $this->roleUser('super-admin', ['name' => 'Root Admin']);
        $this->lead = $this->roleUser('team-lead', ['name' => 'Ayesha Khan']);
        $this->member = $this->roleUser('team', ['name' => 'Bilal Ahmed']);
        $this->outsideLead = $this->roleUser('team-lead', ['name' => 'Chandni Rao']);

        $this->team = $this->makeTeam('Web', $this->lead, [$this->lead, $this->member]);
        $theirTeam = $this->makeTeam('Graphics', $this->outsideLead, [$this->outsideLead]);

        $this->project = $this->makeProject($this->team);
        $this->theirProject = $this->makeProject($theirTeam);

        $this->project->update([
            'start_date' => Project::dayKey($this->monday()),
            'due_date' => Project::dayKey($this->monday()->copy()->addDays(10)),
        ]);
        $this->theirProject->update([
            'due_date' => Project::dayKey($this->monday()->copy()->addDays(4)),
        ]);

        // One task the member holds, one on the same team that they never did.
        $this->mine = $this->makeTask($this->team, [
            'project_id' => $this->project->id,
            'title' => 'Rebuild the checkout',
            'due_date' => Task::dayKey($this->monday()->copy()->addDays(3)),
        ]);
        $this->makeStint($this->mine, $this->member, 3, $this->monday());

        $this->theirs = $this->makeTask($this->team, [
            'project_id' => $this->project->id,
            'title' => 'Rewrite the footer',
            'due_date' => Task::dayKey($this->monday()->copy()->addDays(5)),
        ]);
        $this->makeStint($this->theirs, $this->lead, 2, $this->monday());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Every entry label in a month, under one toggle. */
    protected function labels(User $viewer, string $scope): array
    {
        return TeamCalendar::month($viewer, $this->monday(), $scope)
            ->flatten(1)
            ->pluck('label')
            ->sort()
            ->values()
            ->all();
    }

    protected function sources(User $viewer, string $scope): array
    {
        return TeamCalendar::month($viewer, $this->monday(), $scope)
            ->flatten(1)
            ->pluck('source')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /* ------------------------------ The toggle ------------------------------ */

    /**
     * The named case: a task on your team, but not yours.
     *
     * "My teams" shows it; "Mine" does not, because Mine is work assigned to you
     * plus your own leave, and a team's board is not that.
     */
    public function test_a_task_shows_under_my_teams_and_hides_under_mine(): void
    {
        $this->assertContains('Rewrite the footer', $this->labels($this->lead, TeamCalendar::TEAMS));
        $this->assertNotContains('Rewrite the footer', $this->labels($this->member, TeamCalendar::MINE));

        // Their own is on both.
        $this->assertContains('Rebuild the checkout', $this->labels($this->member, TeamCalendar::MINE));
        $this->assertContains('Rebuild the checkout', $this->labels($this->member, TeamCalendar::TEAMS));
    }

    /**
     * THE TOGGLE CAN ONLY NARROW.
     *
     * A member asking for "My teams" does not get their team's whole board: the
     * scope has already said they see the tasks they hold or held a stint on,
     * and a preference is not a permission.
     */
    public function test_my_teams_does_not_widen_a_members_task_scope(): void
    {
        $labels = $this->labels($this->member, TeamCalendar::TEAMS);

        $this->assertContains('Rebuild the checkout', $labels);
        $this->assertNotContains('Rewrite the footer', $labels);
    }

    /** A lead sees no entry for another team's project under any toggle. */
    public function test_a_lead_sees_nothing_of_another_teams_project_under_any_toggle(): void
    {
        foreach (array_keys(TeamCalendar::scopes($this->lead)) as $scope) {
            $labels = $this->labels($this->lead, $scope);

            foreach ($labels as $label) {
                $this->assertStringNotContainsString(
                    $this->theirProject->code,
                    $label,
                    "A lead sees another team's project under \"{$scope}\".",
                );
            }

            $notes = TeamCalendar::month($this->lead, $this->monday(), $scope)
                ->flatten(1)->pluck('note')->filter()->implode(' ');

            $this->assertStringNotContainsString($this->theirProject->code, $notes);
        }
    }

    /** "Everything" is offered to an admin, and to nobody else. */
    public function test_only_an_admin_is_offered_everything(): void
    {
        $this->assertSame(['mine', 'teams', 'all'], array_keys(TeamCalendar::scopes($this->admin)));
        $this->assertSame(['mine', 'teams'], array_keys(TeamCalendar::scopes($this->lead)));
        $this->assertSame(['mine', 'teams'], array_keys(TeamCalendar::scopes($this->member)));

        // And asking for it anyway is a preference that is not honoured, never a
        // refusal: resolveScope hands back the first toggle they do hold.
        $this->assertSame('mine', TeamCalendar::resolveScope($this->lead, 'all'));
        $this->assertSame('all', TeamCalendar::resolveScope($this->admin, 'all'));
    }

    public function test_an_admin_asking_for_everything_sees_both_teams(): void
    {
        $notes = TeamCalendar::month($this->admin, $this->monday(), TeamCalendar::ALL)
            ->flatten(1)->pluck('note')->filter()->implode(' ');

        $this->assertStringContainsString($this->project->code, $notes);
        $this->assertStringContainsString($this->theirProject->code, $notes);
    }

    /* ------------------------------ The sources ----------------------------- */

    public function test_all_five_sources_appear(): void
    {
        $this->seedEveryKindOfEntry();

        $sources = $this->sources($this->lead, TeamCalendar::TEAMS);

        foreach (['project-start', 'project-due', 'milestone', 'task', 'leave', 'holiday'] as $source) {
            $this->assertContains($source, $sources, "the {$source} source is missing");
        }
    }

    public function test_only_approved_leave_appears(): void
    {
        // Applied for but not reviewed: not a fact about the month yet.
        $this->actingAs($this->member)->post(route('admin.team-leave.store'), [
            'from_date' => $this->monday()->copy()->addDays(7)->toDateString(),
            'to_date' => $this->monday()->copy()->addDays(7)->toDateString(),
            'reason' => 'Dentist',
        ])->assertSessionHasNoErrors();

        $this->assertNotContains('leave', $this->sources($this->member, TeamCalendar::MINE));

        $this->approveLeaveFor($this->member, $this->monday()->copy()->addDays(8), $this->monday()->copy()->addDays(8));

        $this->assertContains('leave', $this->sources($this->member, TeamCalendar::MINE));
    }

    public function test_mine_shows_your_own_leave_and_not_a_teammates(): void
    {
        $this->approveLeaveFor($this->member, $this->monday()->copy()->addDays(8), $this->monday()->copy()->addDays(8));
        $this->approveLeaveFor($this->lead, $this->monday()->copy()->addDays(9), $this->monday()->copy()->addDays(9));

        $this->assertContains('Bilal Ahmed on leave', $this->labels($this->member, TeamCalendar::MINE));
        $this->assertNotContains('Ayesha Khan on leave', $this->labels($this->member, TeamCalendar::MINE));

        // On the team view, both: what a calendar is for is knowing who is around.
        $this->assertContains('Ayesha Khan on leave', $this->labels($this->member, TeamCalendar::TEAMS));
    }

    /** A holiday closes the academy for everybody, on every toggle. */
    public function test_a_holiday_appears_under_every_toggle(): void
    {
        Holiday::create([
            'date' => Holiday::dayKey($this->monday()->copy()->addDays(6)),
            'name' => 'Eid',
        ]);

        foreach (array_keys(TeamCalendar::scopes($this->member)) as $scope) {
            $this->assertContains('Eid', $this->labels($this->member, $scope), $scope);
        }
    }

    /* ---------------------------- Client and money --------------------------- */

    /**
     * No contract value and no client name renders on the calendar.
     *
     * Checked on the rendered page as well as on the data, because the entries
     * could be clean and a view could still reach for `$project->client`.
     */
    public function test_no_contract_value_or_client_name_renders(): void
    {
        $this->seedEveryKindOfEntry();

        foreach ([$this->lead, $this->member, $this->admin] as $viewer) {
            $html = $this->actingAs($viewer)
                ->get(route('admin.calendar.index', ['month' => $this->monday()->format('Y-m'), 'scope' => 'teams']))
                ->assertOk()
                ->getContent();

            // The client's name, their company, and the contract value — the
            // three things a team person never sees, from BuildsTeamPortal.
            $this->assertStringNotContainsString('Zephyrine', $html);
            $this->assertStringNotContainsString('Bartleby', $html);
            $this->assertStringNotContainsString('987654', $html);
            $this->assertStringNotContainsString('987,654', $html);
        }
    }

    public function test_the_page_names_the_project_by_name_and_code(): void
    {
        $html = $this->actingAs($this->lead)
            ->get(route('admin.calendar.index', ['month' => $this->monday()->format('Y-m'), 'scope' => 'teams']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Website Redesign', $html);
    }

    /* ------------------------------ The screen ------------------------------- */

    public function test_every_team_role_opens_the_calendar_and_an_instructor_does_not(): void
    {
        foreach ([$this->admin, $this->lead, $this->member] as $viewer) {
            $this->actingAs($viewer)->get(route('admin.calendar.index'))->assertOk();
        }

        $this->actingAs($this->roleUser('instructor'))->get(route('admin.calendar.index'))->assertForbidden();
        $this->actingAs($this->roleUser('client'))->get(route('admin.calendar.index'))->assertForbidden();
    }

    public function test_a_nonsense_month_is_this_month_rather_than_an_error(): void
    {
        $this->actingAs($this->lead)
            ->get(route('admin.calendar.index', ['month' => 'not-a-month']))
            ->assertOk()
            ->assertSee(Carbon::today()->format('F Y'));
    }

    /* ----------------------------- The query count --------------------------- */

    /**
     * A month with entries from all five sources, MEASURED.
     *
     * A calendar that runs a query per day is the same mistake StintClock made,
     * and a query-count assertion is what caught that one. THE PROPERTY BEING
     * DEFENDED is that thirty-one days of entries cost what one day costs — so
     * the fixture puts an entry on every day of the month, because the mistake
     * this watches for only shows up when there are days to loop over.
     *
     * Ten at the time of writing:
     *
     *   2  projects (starts and dues in ONE query) + their statuses
     *   3  milestones + their projects + those projects' statuses
     *   3  tasks + their statuses + their projects
     *   1  approved leave, joined to its application and the member
     *   1  holidays, through AcademyCalendar
     *
     * Twelve is the ceiling: room for one more eager load, and none for a loop.
     */
    public function test_the_month_view_costs_a_fixed_number_of_queries(): void
    {
        $this->seedEveryKindOfEntry();
        $this->seedAnEntryOnEveryDay();

        // Spatie's permission cache, warmed outside the measurement. Four
        // queries load the whole matrix once per request, before any controller
        // runs, and they are the same four whether this screen exists or not —
        // counting them here would measure the framework and hide the thing this
        // assertion is for.
        $this->lead->can('tasks.view');

        DB::enableQueryLog();
        $days = TeamCalendar::month($this->lead, $this->monday(), TeamCalendar::TEAMS);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, "\n  [query count] calendar month, all five sources, an entry every day: {$queries} queries\n");

        // Asserted, not assumed: a month that came back empty would make the
        // count meaningless and this pass.
        $this->assertGreaterThanOrEqual(28, $days->count(), 'the fixture did not produce a full month');

        $this->assertLessThanOrEqual(12, $queries,
            'the calendar is running a query per day or per row. One query per source, plus its colour.');
    }

    /** And a month with nothing in it does not pay for the sources it has none of. */
    public function test_an_empty_month_costs_one_query_per_source_and_no_eager_loads(): void
    {
        $this->lead->can('tasks.view');

        DB::enableQueryLog();
        TeamCalendar::month($this->lead, $this->monday()->copy()->addYear(), TeamCalendar::TEAMS);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Five source queries and no relation loads: Eloquent skips an eager
        // load on an empty result, so a quiet month is cheaper than a busy one
        // rather than the same price.
        $this->assertLessThanOrEqual(5, $queries);
    }

    /* -------------------------------- Fixtures ------------------------------- */

    /** One of each of the five sources, inside the month. */
    protected function seedEveryKindOfEntry(): void
    {
        ProjectMilestone::create([
            'project_id' => $this->project->id,
            'name' => 'Design sign-off',
            'due_date' => ProjectMilestone::dayKey($this->monday()->copy()->addDays(2)),
            'sort_order' => 1,
        ]);

        $this->approveLeaveFor($this->member, $this->monday()->copy()->addDays(8), $this->monday()->copy()->addDays(9));

        Holiday::create([
            'date' => Holiday::dayKey($this->monday()->copy()->addDays(6)),
            'name' => 'Eid',
        ]);
    }

    /**
     * A task due on every day of the month.
     *
     * So the query count is measured against a full month rather than a handful
     * of rows — the shape of mistake it is watching for only shows up when there
     * are days to loop over.
     */
    protected function seedAnEntryOnEveryDay(): void
    {
        $day = $this->monday()->copy()->startOfMonth();
        $end = $this->monday()->copy()->endOfMonth();

        for ($i = 0; $day->lessThanOrEqualTo($end); $day->addDay(), $i++) {
            $task = $this->makeTask($this->team, [
                'project_id' => $this->project->id,
                'title' => 'Day '.$day->day,
                'due_date' => Task::dayKey($day),
            ]);

            $this->makeStint($task, $this->lead, 1, $day);
        }
    }
}
