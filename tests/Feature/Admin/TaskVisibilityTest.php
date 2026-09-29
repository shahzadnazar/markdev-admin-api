<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * Who sees which tasks, and what a team person never sees on them.
 *
 *   super-admin, admin   every task
 *   team-lead            every task of every team they are a MEMBER of
 *   team                 their own stints only
 *
 * One scope, Task::visibleTo, and no screen writes its own filter. A task
 * outside your scope is a 404, matching projects.show: a refusal would confirm
 * it exists, which is already a fact about somebody else's work.
 *
 * The money and the client stay invisible. The absence assertions use values
 * that appear nowhere else on the page, so a partial match cannot pass by luck.
 */
class TaskVisibilityTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected const CLIENT_NAME = 'Zephyrine Quartermain';

    protected const CLIENT_COMPANY = 'Bartleby Ironworks PLC';

    protected User $admin;

    protected User $lead;

    protected User $member;

    protected User $stranger;

    protected Task $alphaTask;

    protected Task $betaTask;

    protected Task $gammaTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();

        $this->admin = $this->roleUser('super-admin');
        $this->lead = $this->roleUser('team-lead', ['name' => 'Lead Person']);
        $this->member = $this->roleUser('team', ['name' => 'Member Person']);
        $this->stranger = $this->roleUser('team', ['name' => 'Stranger Person']);

        $client = Client::create([
            'name' => self::CLIENT_NAME,
            'company' => self::CLIENT_COMPANY,
            'email' => 'zq@bartleby-ironworks.test',
            'is_active' => true,
        ]);

        // Alpha: the lead runs it. Beta: the lead is only a member. Gamma:
        // neither of them is on it at all.
        $alpha = $this->makeTeam('Alpha', $this->lead, [$this->lead, $this->member]);
        $beta = $this->makeTeam('Beta', $this->stranger, [$this->stranger, $this->lead]);
        $gamma = $this->makeTeam('Gamma', $this->stranger, [$this->stranger]);

        $alphaProject = $this->makeProject($alpha, $client);
        $betaProject = $this->makeProject($beta, $client);

        $this->alphaTask = $this->makeTask($alpha, ['title' => 'Alpha work', 'project_id' => $alphaProject->id]);
        $this->betaTask = $this->makeTask($beta, ['title' => 'Beta work', 'project_id' => $betaProject->id]);
        $this->gammaTask = $this->makeTask($gamma, ['title' => 'Gamma work']);

        // The member holds the Alpha task and nothing else.
        $this->makeStint($this->alphaTask, $this->member, 5, $this->monday());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Everything a team person must never see, in every form it could take. */
    protected function assertHidesClientAndMoney(TestResponse $response): void
    {
        foreach ([
            self::CLIENT_NAME,
            self::CLIENT_COMPANY,
            'zq@bartleby-ironworks.test',
            '987,654.32',
            '987,654',
            '987654.32',
            'PKR',
        ] as $secret) {
            $response->assertDontSee($secret, false);
        }
    }

    /* -------------------------------- The rows ------------------------------ */

    public function test_the_scope_is_three_tiers(): void
    {
        $this->assertSame(
            ['Alpha work', 'Beta work', 'Gamma work'],
            Task::visibleTo($this->admin)->orderBy('title')->pluck('title')->all(),
        );

        // Membership, not the team they lead: scoping on teams.team_lead_id
        // would have lost Beta.
        $this->assertSame(
            ['Alpha work', 'Beta work'],
            Task::visibleTo($this->lead)->orderBy('title')->pluck('title')->all(),
        );

        // Their own stints only — not their team's board.
        $this->assertSame(
            ['Alpha work'],
            Task::visibleTo($this->member)->orderBy('title')->pluck('title')->all(),
        );

        $this->assertSame([], Task::visibleTo(null)->pluck('title')->all());
    }

    public function test_a_member_cannot_see_another_members_stint(): void
    {
        // The stranger holds nothing on Alpha, so the task — and with it every
        // stint on it — is simply not there.
        $this->assertSame([], Task::visibleTo($this->stranger)->pluck('title')->all());

        $this->actingAs($this->stranger)
            ->get(route('admin.tasks.show', $this->alphaTask))
            ->assertNotFound();
    }

    public function test_a_task_outside_your_scope_is_not_found(): void
    {
        $this->actingAs($this->lead)->get(route('admin.tasks.show', $this->gammaTask))->assertNotFound();
        $this->actingAs($this->member)->get(route('admin.tasks.show', $this->betaTask))->assertNotFound();
    }

    public function test_the_task_list_is_scoped(): void
    {
        $this->actingAs($this->lead)->get(route('admin.tasks.index'))
            ->assertOk()->assertSee('Alpha work')->assertSee('Beta work')->assertDontSee('Gamma work');

        $this->actingAs($this->member)->get(route('admin.tasks.index'))
            ->assertOk()->assertSee('Alpha work')->assertDontSee('Beta work')->assertDontSee('Gamma work');
    }

    /* ------------------------------- The fields ----------------------------- */

    public function test_a_team_leads_task_page_shows_neither_the_client_nor_the_money(): void
    {
        $response = $this->actingAs($this->lead)
            ->get(route('admin.tasks.show', $this->alphaTask))
            ->assertOk()
            ->assertSee('Alpha work')
            // The project is named by code, which is what the code is for.
            ->assertSee($this->alphaTask->project->code);

        $this->assertHidesClientAndMoney($response);
    }

    public function test_a_team_leads_task_list_shows_neither(): void
    {
        $this->assertHidesClientAndMoney(
            $this->actingAs($this->lead)->get(route('admin.tasks.index'))->assertOk(),
        );
    }

    public function test_a_team_leads_board_shows_neither(): void
    {
        $this->assertHidesClientAndMoney(
            $this->actingAs($this->lead)->get(route('admin.tasks.board'))->assertOk(),
        );
    }

    public function test_a_members_screens_show_neither(): void
    {
        $this->assertHidesClientAndMoney(
            $this->actingAs($this->member)->get(route('admin.tasks.show', $this->alphaTask))->assertOk(),
        );

        $this->assertHidesClientAndMoney(
            $this->actingAs($this->member)->get(route('admin.tasks.board'))->assertOk(),
        );
    }

    /* -------------------------------- Scores -------------------------------- */

    public function test_a_member_cannot_reach_a_teams_scoreboard(): void
    {
        $alpha = $this->alphaTask->team;

        // `teams.view` is a lead's permission. Somebody else's score is
        // somebody else's business.
        $this->actingAs($this->member)->get(route('admin.teams.scores', $alpha))->assertForbidden();

        $this->actingAs($this->lead)->get(route('admin.teams.scores', $alpha))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.teams.scores', $alpha))->assertOk();
    }

    public function test_a_lead_cannot_reach_the_scoreboard_of_a_team_they_are_not_on(): void
    {
        $this->actingAs($this->lead)
            ->get(route('admin.teams.scores', $this->gammaTask->team))
            ->assertNotFound();
    }

    /* -------------------------------- The board ----------------------------- */

    public function test_the_board_is_scoped_like_everything_else(): void
    {
        $this->actingAs($this->member)->get(route('admin.tasks.board'))
            ->assertOk()->assertSee('Alpha work')->assertDontSee('Gamma work');
    }

    public function test_a_member_cannot_move_a_task_they_do_not_hold(): void
    {
        $this->actingAs($this->stranger)
            ->post(route('admin.tasks.move', $this->alphaTask), [
                'task_status_id' => $this->taskStatus('done')->id,
            ])
            ->assertNotFound();
    }
}
