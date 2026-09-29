<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What a team person may see, and what they must never see.
 *
 * WHICH ROWS: only projects of teams they are a MEMBER of. Membership, not
 * teams.team_lead_id — a lead runs one team and may be a member of others, and
 * is entitled to all of them.
 *
 * WHICH FIELDS: never the client — not the name, the company, the contact
 * details or the id in a URL — and never any money figure. They identify a
 * project by its name and its code, which is what the code is for.
 *
 * The absence assertions use values chosen to appear nowhere else on the page,
 * so a partial match cannot pass by luck.
 */
class ProjectVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected const CLIENT_NAME = 'Zephyrine Quartermain';

    protected const CLIENT_COMPANY = 'Bartleby Ironworks PLC';

    protected const CLIENT_EMAIL = 'zq@bartleby-ironworks.test';

    protected const CLIENT_PHONE = '+92-300-7654321';

    /** Rendered as "987,654.32" and "987,654"; the raw form has no comma. */
    protected const CONTRACT_VALUE = '987654.32';

    protected User $admin;

    protected User $lead;

    protected User $member;

    protected User $outsider;

    protected Client $client;

    protected Project $alpha;

    protected Project $beta;

    protected Project $gamma;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');

        $this->lead = User::factory()->create(['name' => 'Lead Person']);
        $this->lead->assignRole('team-lead');

        $this->member = User::factory()->create(['name' => 'Member Person']);
        $this->member->assignRole('team');

        $this->outsider = User::factory()->create(['name' => 'Outsider Person']);

        $this->client = Client::create([
            'name' => self::CLIENT_NAME,
            'company' => self::CLIENT_COMPANY,
            'email' => self::CLIENT_EMAIL,
            'phone' => self::CLIENT_PHONE,
            'is_active' => true,
        ]);

        // Alpha: the lead's own team. Beta: a team they are merely a member of.
        // Gamma: neither. The member is on Alpha and Beta too.
        $alphaTeam = $this->team('Alpha', $this->lead, [$this->lead, $this->member]);
        $betaTeam = $this->team('Beta', $this->outsider, [$this->outsider, $this->lead, $this->member]);
        $gammaTeam = $this->team('Gamma', $this->outsider, [$this->outsider]);

        $this->alpha = $this->project('Alpha Rebuild', 'PRJ-ALPHA', $alphaTeam);
        $this->beta = $this->project('Beta Rebuild', 'PRJ-BETA', $betaTeam);
        $this->gamma = $this->project('Gamma Rebuild', 'PRJ-GAMMA', $gammaTeam);
    }

    /* ------------------------------- Helpers ------------------------------- */

    /** @param  array<int, User>  $members */
    protected function team(string $name, User $lead, array $members): Team
    {
        $team = Team::create(['name' => $name, 'team_lead_id' => $lead->id, 'is_active' => true]);
        $team->members()->sync(collect($members)->pluck('id')->all());

        return $team;
    }

    protected function project(string $name, string $code, Team $team): Project
    {
        return Project::create([
            'name' => $name,
            'code' => $code,
            'client_id' => $this->client->id,
            'team_id' => $team->id,
            'project_status_id' => ProjectStatus::where('behaviour', 'running')->active()->firstOrFail()->id,
            'contract_value' => self::CONTRACT_VALUE,
            'currency' => 'PKR',
            'start_date' => today()->subDays(3)->toDateString(),
            'due_date' => today()->addDays(9)->toDateString(),
        ]);
    }

    /** Every form the client and the money could reach the page in. */
    protected function assertHidesClientAndMoney(TestResponse $response): void
    {
        foreach ([
            self::CLIENT_NAME,
            self::CLIENT_COMPANY,
            self::CLIENT_EMAIL,
            self::CLIENT_PHONE,
            // The money, as the views would format it and as it is stored.
            '987,654.32',
            '987,654',
            self::CONTRACT_VALUE,
            // The currency label only ever appears beside a figure.
            'PKR',
            // And the client's id in a link, which would name them by number.
            '/admin/clients/'.$this->client->id,
        ] as $secret) {
            $response->assertDontSee($secret, false);
        }
    }

    /* ------------------------------ The fields ------------------------------ */

    public function test_a_team_leads_project_list_shows_neither_the_client_nor_the_money(): void
    {
        $response = $this->actingAs($this->lead)->get(route('admin.projects.index'))->assertOk();

        // The project itself is there — they can do their job.
        $response->assertSee('Alpha Rebuild')->assertSee('PRJ-ALPHA');

        $this->assertHidesClientAndMoney($response);
    }

    public function test_a_team_leads_project_page_shows_neither_the_client_nor_the_money(): void
    {
        $response = $this->actingAs($this->lead)
            ->get(route('admin.projects.show', $this->alpha))
            ->assertOk()
            ->assertSee('Alpha Rebuild')
            ->assertSee('PRJ-ALPHA');

        $this->assertHidesClientAndMoney($response);
    }

    public function test_a_team_members_screens_hide_them_too(): void
    {
        $this->assertHidesClientAndMoney(
            $this->actingAs($this->member)->get(route('admin.projects.index'))->assertOk(),
        );

        $this->assertHidesClientAndMoney(
            $this->actingAs($this->member)->get(route('admin.projects.show', $this->beta))->assertOk(),
        );
    }

    public function test_an_admin_sees_both(): void
    {
        // The other half of the rule: the gate hides these from a team, not
        // from the people whose job is the commercial side.
        $this->actingAs($this->admin)->get(route('admin.projects.index'))
            ->assertOk()
            ->assertSee(self::CLIENT_COMPANY)
            ->assertSee('987,654');

        $this->actingAs($this->admin)->get(route('admin.projects.show', $this->alpha))
            ->assertOk()
            ->assertSee(self::CLIENT_COMPANY)
            ->assertSee('987,654.32');
    }

    /* ------------------------------- The rows ------------------------------- */

    public function test_a_lead_sees_the_team_they_run_and_the_teams_they_are_on(): void
    {
        $response = $this->actingAs($this->lead)->get(route('admin.projects.index'))->assertOk();

        // Alpha is theirs to lead; Beta they are only a member of. Scoping on
        // teams.team_lead_id would have lost Beta.
        $response->assertSee('PRJ-ALPHA')->assertSee('PRJ-BETA')->assertDontSee('PRJ-GAMMA');
    }

    public function test_a_member_sees_every_team_they_are_on_and_no_other(): void
    {
        $this->actingAs($this->member)->get(route('admin.projects.index'))
            ->assertOk()
            ->assertSee('PRJ-ALPHA')
            ->assertSee('PRJ-BETA')
            ->assertDontSee('PRJ-GAMMA');
    }

    public function test_the_scope_itself_agrees_with_the_screen(): void
    {
        $this->assertSame(
            ['PRJ-ALPHA', 'PRJ-BETA'],
            Project::visibleTo($this->lead)->orderBy('code')->pluck('code')->all(),
        );

        $this->assertSame(
            ['PRJ-ALPHA', 'PRJ-BETA'],
            Project::visibleTo($this->member)->orderBy('code')->pluck('code')->all(),
        );

        $this->assertSame(
            ['PRJ-ALPHA', 'PRJ-BETA', 'PRJ-GAMMA'],
            Project::visibleTo($this->admin)->orderBy('code')->pluck('code')->all(),
        );

        // Nobody signed in sees nothing, rather than everything.
        $this->assertSame([], Project::visibleTo(null)->pluck('code')->all());
    }

    public function test_a_project_outside_your_teams_is_not_found(): void
    {
        // 404, not 403: a refusal would confirm the project exists, which is
        // already a fact about somebody else's client work.
        $this->actingAs($this->lead)->get(route('admin.projects.show', $this->gamma))->assertNotFound();
        $this->actingAs($this->member)->get(route('admin.projects.show', $this->gamma))->assertNotFound();
    }

    public function test_the_team_filter_offers_only_the_teams_you_are_on(): void
    {
        $this->actingAs($this->lead)->get(route('admin.projects.index'))
            ->assertOk()
            ->assertSee('Alpha')
            ->assertSee('Beta')
            // A filter naming a team whose work they cannot see is a list of
            // teams they were not supposed to be given either.
            ->assertDontSee('Gamma');
    }

    public function test_a_client_filter_posted_by_hand_does_not_unlock_anything(): void
    {
        // Ignored rather than refused — a copied link is not an attack — and it
        // still cannot widen what the scope already decided.
        $response = $this->actingAs($this->member)
            ->get(route('admin.projects.index', ['client' => $this->client->id]))
            ->assertOk();

        $response->assertSee('PRJ-ALPHA')->assertDontSee('PRJ-GAMMA');
        $this->assertHidesClientAndMoney($response);
    }
}
