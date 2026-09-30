<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * A required dropdown with nothing in it says what is missing and where to go.
 *
 * The same fault as the Dashboard breadcrumb that answered 403: something the
 * screen offers that cannot be used, with no explanation. Here the form simply
 * cannot be submitted — the field is required and has no options — and before
 * this the page said nothing at all about why. That is what cost somebody
 * twenty minutes on the client workflow: a silently empty client dropdown.
 *
 * THE LINK IS ASSERTED TO RESOLVE, not just to be present. A message pointing at
 * a route that 403s or 404s is the original bug with extra words.
 */
class EmptyPrerequisiteTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ----------------------------- Project form ----------------------------- */

    public function test_the_project_form_with_no_clients_says_so_and_links_to_creating_one(): void
    {
        $this->makeTeam('Web');

        $response = $this->actingAs($this->admin)->get(route('admin.projects.create'))->assertOk();

        $response->assertSee('No client exists yet');
        $response->assertSee(route('admin.clients.create'), escape: false);
        $response->assertDontSee('Pick a client');

        // The link is only useful if it opens.
        $this->actingAs($this->admin)->get(route('admin.clients.create'))->assertOk();
    }

    public function test_the_project_form_with_no_teams_says_so_and_links_to_creating_one(): void
    {
        Client::create(['name' => 'Bartleby Ironworks PLC', 'is_active' => true]);

        $response = $this->actingAs($this->admin)->get(route('admin.projects.create'))->assertOk();

        $response->assertSee('No team exists yet');
        $response->assertSee(route('admin.teams.create'), escape: false);
        $response->assertDontSee('Pick a team');

        $this->actingAs($this->admin)->get(route('admin.teams.create'))->assertOk();
    }

    /**
     * Switched off is not the same as absent, and the advice differs.
     *
     * "Add the first client" is wrong for somebody who has one and turned it off;
     * they need the list, where the toggle is.
     */
    public function test_a_client_that_exists_but_is_switched_off_is_not_told_to_create_another(): void
    {
        $this->makeTeam('Web');
        Client::create(['name' => 'Bartleby Ironworks PLC', 'is_active' => false]);

        $response = $this->actingAs($this->admin)->get(route('admin.projects.create'))->assertOk();

        $response->assertSee('Every client is switched off for new projects');
        $response->assertDontSee('No client exists yet');
        $response->assertSee(route('admin.clients.index'), escape: false);

        $this->actingAs($this->admin)->get(route('admin.clients.index'))->assertOk();
    }

    public function test_a_team_that_exists_but_is_switched_off_is_not_told_to_create_another(): void
    {
        Client::create(['name' => 'Bartleby Ironworks PLC', 'is_active' => true]);
        $team = $this->makeTeam('Web');
        $team->update(['is_active' => false]);

        $response = $this->actingAs($this->admin)->get(route('admin.projects.create'))->assertOk();

        $response->assertSee('Every team is switched off');
        $response->assertDontSee('No team exists yet');
    }

    /** With both present the form is its ordinary self. */
    public function test_the_project_form_with_both_shows_the_dropdowns(): void
    {
        Client::create(['name' => 'Bartleby Ironworks PLC', 'is_active' => true]);
        $this->makeTeam('Web');

        $response = $this->actingAs($this->admin)->get(route('admin.projects.create'))->assertOk();

        $response->assertSee('Pick a client');
        $response->assertSee('Pick a team');
        $response->assertDontSee('No client exists yet');
        $response->assertDontSee('No team exists yet');
    }

    /* ------------------------------- Task form ------------------------------ */

    public function test_the_task_form_with_no_teams_says_so_and_links_for_somebody_who_may_act(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.tasks.create'))->assertOk();

        $response->assertSee('No team is available');
        $response->assertSee(route('admin.teams.create'), escape: false);
    }

    /**
     * A team lead is told what is missing and NOT handed a link that refuses them.
     *
     * `team-lead` holds tasks.create but not teams.create, so offering "add the
     * first team" here would be the breadcrumb bug again — something offered that
     * answers 403.
     */
    public function test_a_lead_on_no_team_is_told_without_being_offered_a_link_that_would_refuse(): void
    {
        $lead = User::factory()->create();
        $lead->assignRole('team-lead');

        $response = $this->actingAs($lead)->get(route('admin.tasks.create'))->assertOk();

        $response->assertSee('You are not on a team yet');
        $response->assertDontSee(route('admin.teams.create'), escape: false);

        // And the link withheld is withheld for a reason.
        $this->actingAs($lead)->get(route('admin.teams.create'))->assertForbidden();
    }

    /* ------------------------------ Assign form ----------------------------- */

    /**
     * A team whose members have all been deleted leaves nobody to assign to.
     *
     * A team is created with at least one member and its lead must be one of
     * them, so this needs the accounts gone rather than merely removed.
     */
    public function test_the_assign_form_with_no_members_left_says_so_and_links_to_the_team(): void
    {
        $member = $this->roleUser('team', ['name' => 'Only Member']);
        $team = $this->makeTeam('Web', $member, [$member]);
        $task = $this->makeTask($team);

        $member->delete();

        $response = $this->actingAs($this->admin)->get(route('admin.tasks.assign', $task))->assertOk();

        $response->assertSee('No one is on Web any more');
        $response->assertSee(route('admin.teams.edit', $team), escape: false);
        $response->assertDontSee('Pick a member');

        $this->actingAs($this->admin)->get(route('admin.teams.edit', $team))->assertOk();
    }

    /** With a member there, the dropdown is the dropdown. */
    public function test_the_assign_form_with_a_member_shows_the_dropdown(): void
    {
        $member = $this->roleUser('team', ['name' => 'Only Member']);
        $team = $this->makeTeam('Web', $member, [$member]);
        $task = $this->makeTask($team);

        $this->actingAs($this->admin)->get(route('admin.tasks.assign', $task))
            ->assertOk()
            ->assertSee('Pick a member')
            ->assertDontSee('No one is on Web any more');
    }

    /* ------------------------------- Users form ----------------------------- */

    /**
     * Ticking `client` on the Users form says what it does not do.
     *
     * The reveal is Alpine's, so what is asserted is that the line and its link
     * are in the page and that the link opens — which is the part that can rot.
     */
    public function test_the_users_form_explains_what_the_client_role_does_not_do(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.users.create'))->assertOk();

        $response->assertSee('does not give portal access', escape: false);
        $response->assertSee('The portal needs a client', escape: false);
        $response->assertSee(route('admin.clients.create'), escape: false);
        $response->assertSee("roles.includes('client')", escape: false);

        $this->actingAs($this->admin)->get(route('admin.clients.create'))->assertOk();
    }

    /**
     * The roles a user already holds stay ticked by the SERVER.
     *
     * Adding x-model to those checkboxes to drive the line above put this at
     * risk: with the checked attribute dropped, a page where Alpine failed to
     * load would post an empty roles[] and strip the roles it was showing. Both
     * read the same array, and this is what says the server half is still there.
     */
    public function test_the_edit_form_ticks_the_roles_a_user_already_holds(): void
    {
        $person = User::factory()->create();
        $person->assignRole('team-lead');

        $html = $this->actingAs($this->admin)->get(route('admin.users.edit', $person))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/value="team-lead"[^>]*checked/',
            $html,
            'The role is not checked server-side, so a page without Alpine would post it away.',
        );
    }

    /** The checkbox itself is untouched — an existing account still needs the role. */
    public function test_the_client_checkbox_is_still_offered(): void
    {
        $this->actingAs($this->admin)->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('value="client"', escape: false);
    }

    /** Sanity: the fixture the assign test leans on really does empty the team. */
    public function test_a_deleted_member_leaves_the_team_empty(): void
    {
        $member = $this->roleUser('team', ['name' => 'Only Member']);
        $team = $this->makeTeam('Web', $member, [$member]);
        $this->makeTask($team);

        $member->delete();

        $this->assertSame(0, Team::find($team->getKey())->members()->count());
        $this->assertSame(1, Task::count());
    }
}
