<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\ClientQuestion;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamAbsenceFine;
use App\Models\TeamAttendance;
use App\Models\User;
use App\Services\DeliveryScoreCache;
use App\Support\TeamDashboard;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * One screen, three audiences, every figure through the existing scopes.
 *
 * The absence assertions use DISTINCTIVE seeded strings — "Bartleby Ironworks
 * PLC", "987654.32", "Quinces Ravensworth" — so an assertion that a colleague's
 * fine or a contract value is absent means what it says. A common word would
 * pass by luck against a page that already says "Team" or "Completed".
 */
class TeamDashboardTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected User $admin;

    protected User $lead;

    protected User $member;

    protected User $otherLead;

    protected Team $team;

    protected Team $otherTeam;

    protected Project $project;

    protected Project $otherProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();

        $this->admin = $this->roleUser('super-admin', ['name' => 'Root Admin']);
        $this->lead = $this->roleUser('team-lead', ['name' => 'Ayesha Khan']);
        $this->member = $this->roleUser('team', ['name' => 'Bilal Ahmed']);
        $this->otherLead = $this->roleUser('team-lead', ['name' => 'Quinces Ravensworth']);

        $this->team = $this->makeTeam('Peregrine Web Squad', $this->lead, [$this->lead, $this->member]);
        $this->otherTeam = $this->makeTeam('Marmalade Graphics', $this->otherLead, [$this->otherLead]);

        $client = Client::create([
            'name' => 'Zephyrine Quartermain',
            'company' => 'Bartleby Ironworks PLC',
            'is_active' => true,
        ]);

        $this->project = $this->makeProject($this->team, $client);
        $this->project->update(['code' => 'PRJ-OURS']);

        $this->otherProject = $this->makeProject($this->otherTeam, $client);
        $this->otherProject->update(['code' => 'PRJ-THEIRS']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------ The tiers ------------------------------- */

    public function test_the_tier_is_decided_by_permission_not_by_role_name(): void
    {
        $this->assertSame(TeamDashboard::TIER_EVERYTHING, TeamDashboard::tierFor($this->admin));
        $this->assertSame(TeamDashboard::TIER_TEAMS, TeamDashboard::tierFor($this->lead));
        $this->assertSame(TeamDashboard::TIER_OWN, TeamDashboard::tierFor($this->member));
    }

    public function test_every_team_role_opens_it_and_no_academy_role_does(): void
    {
        foreach ([$this->admin, $this->lead, $this->member] as $viewer) {
            $this->actingAs($viewer)->get(route('admin.team-dashboard'))->assertOk();
        }

        foreach (['manager', 'instructor', 'student', 'client'] as $role) {
            $this->actingAs($this->roleUser($role))
                ->get(route('admin.team-dashboard'))
                ->assertForbidden("a {$role} opened the team dashboard");
        }
    }

    /* ----------------------------- A member's own --------------------------- */

    /**
     * A member sees their OWN work and none of their team's.
     *
     * Not because the view hides it: Task::scopeVisibleTo returns a member the
     * tasks they hold or held a stint on, so their teammate's task is not in
     * the count at all.
     */
    public function test_a_members_dashboard_shows_their_own_figures_and_none_of_their_teams(): void
    {
        $mine = $this->makeTask($this->team, ['project_id' => $this->project->id, 'title' => 'Mine']);
        $this->makeStint($mine, $this->member, 3, $this->monday());

        $theirs = $this->makeTask($this->team, ['project_id' => $this->project->id, 'title' => 'Not mine']);
        $this->makeStint($theirs, $this->lead, 3, $this->monday());

        $data = TeamDashboard::for($this->member);

        $this->assertSame(1, $data['tasks_by_status']->sum('count'));

        // Not a figure they are shown — null, not zero. Zero would read as news.
        foreach (['projects_running', 'member_scores', 'attendance_today', 'leave_pending', 'clients_active', 'contract_value'] as $key) {
            $this->assertNull($data[$key], "a member was given {$key}");
        }

        // And their own figures are there.
        $this->assertIsArray($data['my_score']);
        $this->assertArrayHasKey('remaining', $data['my_leave']);
    }

    public function test_a_members_screen_names_no_colleague_and_no_client(): void
    {
        $this->makeStint(
            $this->makeTask($this->team, ['project_id' => $this->project->id]),
            $this->member,
            3,
            $this->monday(),
        );

        $this->fineFor($this->lead, 5000);

        $html = $this->actingAs($this->member)->get(route('admin.team-dashboard'))->assertOk()->getContent();

        foreach (['Bartleby Ironworks PLC', 'Zephyrine Quartermain', '987654', '987,654', 'Ayesha Khan', '5,000', 'Quinces Ravensworth'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "\"{$needle}\" reached a member's dashboard");
        }
    }

    /* ------------------------------ A lead's -------------------------------- */

    public function test_a_leads_dashboard_shows_their_teams_and_not_another_leads(): void
    {
        $ours = $this->makeTask($this->team, ['project_id' => $this->project->id]);
        $this->makeStint($ours, $this->member, 3, $this->monday());

        $theirs = $this->makeTask($this->otherTeam, ['project_id' => $this->otherProject->id]);
        $this->makeStint($theirs, $this->otherLead, 3, $this->monday());

        $data = TeamDashboard::for($this->lead);

        // One project and one task, not two of each.
        $this->assertSame(1, $data['projects_running']);
        $this->assertSame(1, $data['tasks_by_status']->sum('count'));

        // The other lead's own dashboard is the mirror image, so this is not
        // passing because one side simply has nothing.
        $this->assertSame(1, TeamDashboard::for($this->otherLead)['projects_running']);

        // An admin sees both.
        $this->assertSame(2, TeamDashboard::for($this->admin)['projects_running']);
    }

    public function test_a_lead_sees_no_colleagues_fine_and_no_money(): void
    {
        $this->fineFor($this->member, 5000);

        $html = $this->actingAs($this->lead)->get(route('admin.team-dashboard'))->assertOk()->getContent();

        foreach (['Bartleby Ironworks PLC', 'Zephyrine Quartermain', '987654', '987,654', '5,000'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "\"{$needle}\" reached a lead's dashboard");
        }

        // The all-teams outstanding figure is an admin card and is not even
        // computed for a lead.
        $this->assertNull(TeamDashboard::for($this->lead)['fines_outstanding']);
    }

    /** THEIR OWN fine does render — it is their own money. */
    public function test_a_leads_own_fine_renders_on_their_own_dashboard(): void
    {
        $this->fineFor($this->lead, 3700);

        $this->assertSame(3700.0, TeamDashboard::for($this->lead)['my_fines_owed']);

        $this->actingAs($this->lead)
            ->get(route('admin.team-dashboard'))
            ->assertOk()
            ->assertSee('You owe')
            ->assertSee('3,700');
    }

    /** A settled fine is not owed. */
    public function test_a_settled_fine_is_not_counted_as_owed(): void
    {
        $fine = $this->fineFor($this->lead, 3700);
        $fine->update(['settled_on' => TeamAbsenceFine::dayKey($this->monday())]);

        $this->assertSame(0.0, TeamDashboard::for($this->lead)['my_fines_owed']);
    }

    /* ------------------------------ An admin's ------------------------------ */

    public function test_an_admin_sees_the_clients_the_questions_and_the_money(): void
    {
        ClientQuestion::create([
            'project_id' => $this->project->id,
            'asked_by' => $this->admin->id,
            'body' => 'When is it live?',
        ]);

        $data = TeamDashboard::for($this->admin);

        $this->assertSame(1, $data['clients_active']);
        $this->assertSame(1, $data['client_questions_open']);
        // Two running projects for the same client, at the fixture's value.
        $this->assertSame(987654.32 * 2, round($data['contract_value'], 2));

        $this->actingAs($this->admin)
            ->get(route('admin.team-dashboard'))
            ->assertOk()
            ->assertSee('Contract value running')
            ->assertSee('Client questions open');
    }

    /* ----------------------------- The score card --------------------------- */

    /**
     * No bare percentage anywhere on this screen.
     *
     * The score component refuses to draw one without its counts, and this
     * asserts the screen actually uses that component rather than formatting a
     * number itself.
     */
    public function test_the_score_is_never_rendered_without_its_counts(): void
    {
        $this->setMinimumStints(1);

        $task = $this->makeTask($this->team, ['project_id' => $this->project->id]);
        $this->makeStint($task, $this->member, 4, $this->monday(), $this->monday()->copy()->addDays(2), 'on_time');
        app(DeliveryScoreCache::class)->refresh($this->member);

        foreach ([$this->member, $this->lead] as $viewer) {
            $html = $this->actingAs($viewer)->get(route('admin.team-dashboard'))->assertOk()->getContent();

            $this->assertStringContainsString('100%', $html);

            // The four counts the component always draws beside it.
            foreach (['completed', 'late', 'days over', 'blocked'] as $context) {
                $this->assertStringContainsString($context, $html, "the score lost its \"{$context}\" count");
            }
        }
    }

    /** A lead's member list reads the CACHE, not a calculation per person. */
    public function test_the_member_list_reads_the_cached_scores(): void
    {
        $this->setMinimumStints(1);

        $task = $this->makeTask($this->team, ['project_id' => $this->project->id]);
        $this->makeStint($task, $this->member, 4, $this->monday(), $this->monday()->copy()->addDays(2), 'on_time');
        app(DeliveryScoreCache::class)->refresh($this->member);

        $names = TeamDashboard::memberScores($this->lead)->pluck('name');

        $this->assertContains('Bilal Ahmed', $names);
        $this->assertNotContains('Quinces Ravensworth', $names, "another team's member is in a lead's list");
    }

    /* ---------------------------- The query count --------------------------- */

    /**
     * An admin with several teams, MEASURED.
     *
     * A dashboard that runs a query per team is the mistake StintClock made
     * twice, and a query-count assertion is what caught it both times. The
     * fixture is four teams with members, projects, tasks and stints, so the
     * shape being watched for has something to loop over.
     */
    public function test_the_dashboard_costs_a_fixed_number_of_queries(): void
    {
        $this->seedSeveralTeams();

        // Spatie's permission cache, warmed outside the measurement: four
        // queries load the whole matrix once per request whatever this screen
        // does, and counting them would measure the framework.
        $this->admin->can('tasks.view');

        DB::enableQueryLog();
        $data = TeamDashboard::for($this->admin);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, "\n  [query count] team dashboard, admin, 5 teams: {$queries} queries\n");

        // Asserted, not assumed: an empty dashboard would make the ceiling
        // meaningless and this pass.
        $this->assertGreaterThan(0, $data['projects_running']);
        $this->assertGreaterThan(1, $data['member_scores']->count());

        $this->assertLessThanOrEqual(20, $queries,
            'the dashboard is running a query per team. Every figure is one query with a subquery, '
            .'and the member scores are one read of the cache.');
    }

    /** And five teams cost what one team costs. */
    public function test_the_query_count_does_not_grow_with_the_number_of_teams(): void
    {
        $this->admin->can('tasks.view');

        DB::enableQueryLog();
        TeamDashboard::for($this->admin);
        $withOne = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->seedSeveralTeams();

        // FLUSHED, not just disabled. disableQueryLog() stops recording and
        // keeps what it already has, so a second measurement that only enables
        // the log again counts the first run as well — which looks exactly like
        // a per-team query and is not one.
        DB::flushQueryLog();
        DB::enableQueryLog();
        TeamDashboard::for($this->admin);
        $withSeveral = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($withOne, $withSeveral, sprintf(
            'One team cost %d queries and five cost %d — something is asking per team.',
            $withOne,
            $withSeveral,
        ));
    }

    /* -------------------------------- Fixtures ------------------------------ */

    protected function fineFor(User $user, float $total): TeamAbsenceFine
    {
        return TeamAbsenceFine::create([
            'user_id' => $user->id,
            'month' => TeamAbsenceFine::dayKey($this->monday()->copy()->startOfMonth()),
            'allowance' => 2,
            'absences' => 5,
            'chargeable' => 3,
            'rate' => $total / 3,
            'total' => $total,
        ]);
    }

    protected function setMinimumStints(int $minimum): void
    {
        Setting::updateOrCreate(
            ['key' => 'delivery_minimum_stints'],
            ['value' => $minimum, 'group' => 'general'],
        );
        Setting::forgetCached();
    }

    /** Three more teams, each with a member, a project, a task and a stint. */
    protected function seedSeveralTeams(): void
    {
        foreach (range(1, 3) as $i) {
            $lead = $this->roleUser('team-lead', ['name' => "Lead {$i}"]);
            $member = $this->roleUser('team', ['name' => "Member {$i}"]);
            $team = $this->makeTeam("Squad {$i}", $lead, [$lead, $member]);
            $project = $this->makeProject($team);
            $project->update(['code' => "PRJ-{$i}"]);

            $task = $this->makeTask($team, ['project_id' => $project->id, 'title' => "Task {$i}"]);
            $this->makeStint($task, $member, 3, $this->monday(), $this->monday()->copy()->addDay(), 'on_time');

            app(DeliveryScoreCache::class)->refresh($member);

            TeamAttendance::create([
                'user_id' => $member->id,
                'date' => TeamAttendance::dayKey($this->monday()),
                'status' => 'present',
            ]);
        }
    }
}
