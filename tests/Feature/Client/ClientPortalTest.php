<?php

namespace Tests\Feature\Client;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\Team;
use App\Models\TeamFile;
use App\Models\User;
use App\Support\PortalHome;
use App\Support\PrivateFiles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * What a client sees, and — mostly — what they do not.
 *
 * The absence assertions here use DISTINCTIVE seeded strings on purpose:
 * "Zephyrine Quartermain", "Bartleby Ironworks PLC", "987654.32". A partial
 * match on a common word would pass by luck against a page that says
 * "Completed" or "Team"; none of these appears anywhere by accident, so an
 * assertion that they are absent means what it says.
 */
class ClientPortalTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected User $clientUser;

    protected Client $client;

    protected Project $project;

    protected User $otherClientUser;

    protected Project $otherProject;

    protected Team $team;

    protected User $lead;

    protected User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();

        // Real bytes on a faked private disk, so a permitted fetch is a 200 and
        // a refused one is a 404. Without this every fetch would 404 for want of
        // a file and the assertions could not tell refusal from absence.
        Storage::fake(PrivateFiles::DISK);

        $this->lead = $this->roleUser('team-lead', ['name' => 'Ayesha Khan']);
        $this->member = $this->roleUser('team', ['name' => 'Bilal Ahmed']);
        $this->team = $this->makeTeam('Peregrine Web Squad', $this->lead, [$this->lead, $this->member]);

        [$this->clientUser, $this->client, $this->project] = $this->makeClientWith('Zephyrine Quartermain', 'Bartleby Ironworks PLC', 'PRJ-OURS');
        [$this->otherClientUser, , $this->otherProject] = $this->makeClientWith('Crispin Fallowfield', 'Dunwoody Mercantile', 'PRJ-THEIRS');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A client login, its client record, and one project of theirs. */
    protected function makeClientWith(string $name, string $company, string $code): array
    {
        $user = $this->roleUser('client', ['name' => $name]);

        $client = Client::create([
            'name' => $name,
            'company' => $company,
            'email' => 'billing@example.test',
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $project = $this->makeProject($this->team, $client);
        $project->update([
            'code' => $code,
            'start_date' => Project::dayKey($this->monday()),
            'due_date' => Project::dayKey($this->monday()->copy()->addMonth()),
        ]);

        return [$user, $client, $project->fresh()];
    }

    protected function milestone(Project $project, string $name, bool $clientVisible): ProjectMilestone
    {
        return ProjectMilestone::create([
            'project_id' => $project->id,
            'name' => $name,
            'due_date' => ProjectMilestone::dayKey($this->monday()->copy()->addWeek()),
            'sort_order' => 1,
            'is_client_visible' => $clientVisible,
        ]);
    }

    protected function file(Project $project, string $name, bool $clientVisible): TeamFile
    {
        $path = 'team-files/'.md5($name).'.pdf';
        Storage::disk(PrivateFiles::DISK)->put($path, 'the bytes of '.$name);

        return TeamFile::create([
            'owner_type' => $project->getMorphClass(),
            'owner_id' => $project->id,
            'path' => $path,
            'original_name' => $name,
            'mime' => 'application/pdf',
            'size_bytes' => 2048,
            'uploaded_by' => $this->lead->id,
            'is_client_visible' => $clientVisible,
        ]);
    }

    /* ------------------------------ The landing ----------------------------- */

    public function test_a_client_lands_on_their_project_list_instead_of_the_no_portal_page(): void
    {
        $this->assertSame('client.projects.index', PortalHome::for($this->clientUser));

        $login = $this->post('/login', ['email' => $this->clientUser->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($this->clientUser);

        $login->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('client.projects.index'));
        $this->get(route('client.projects.index'))->assertOk();
    }

    /**
     * A `client` ROLE with no client record still has nowhere to be.
     *
     * The entitlement is the row, not the role — so an account set up but not
     * yet linked gets the no-portal page, which says the account works and an
     * administrator has to finish setting it up.
     */
    public function test_a_client_role_with_no_client_record_gets_the_no_portal_page(): void
    {
        $unlinked = $this->roleUser('client', ['name' => 'Unlinked Person']);

        $this->assertSame(PortalHome::NONE, PortalHome::for($unlinked));

        $this->actingAs($unlinked)->get(route('client.projects.index'))->assertForbidden();
    }

    /** Staff land where they always did; the client check runs last. */
    public function test_a_staff_login_linked_to_a_client_record_is_still_staff(): void
    {
        $admin = $this->roleUser('super-admin');
        Client::create(['name' => 'Odd Arrangement', 'user_id' => $admin->id, 'is_active' => true]);

        $this->assertSame('admin.dashboard', PortalHome::for($admin));
    }

    /* ---------------------------- Their own work ---------------------------- */

    public function test_a_client_sees_only_their_own_projects(): void
    {
        $this->actingAs($this->clientUser)
            ->get(route('client.projects.index'))
            ->assertOk()
            ->assertSee('PRJ-OURS')
            ->assertDontSee('PRJ-THEIRS');
    }

    public function test_another_clients_project_is_a_404(): void
    {
        $this->actingAs($this->clientUser)
            ->get(route('client.projects.show', $this->otherProject))
            ->assertNotFound();

        // And the other way round, so this is not passing because one client
        // simply has nothing.
        $this->actingAs($this->otherClientUser)
            ->get(route('client.projects.show', $this->project))
            ->assertNotFound();

        $this->actingAs($this->otherClientUser)
            ->get(route('client.projects.show', $this->otherProject))
            ->assertOk();
    }

    /**
     * THE SCOPE IS THE SESSION, AND A PARAMETER CANNOT MOVE IT.
     *
     * Written adversarially rather than descriptively: the tests above prove a
     * client sees their own projects, which stays true even if the subject were
     * taken from the URL. This one tries to take it from the URL — with every
     * name a careless implementation might read — and asserts nothing moves.
     *
     * A client portal that takes its subject from the request is one guessed
     * integer away from being every client's portal, and that is the single
     * decision the whole portal rests on.
     */
    public function test_no_request_parameter_can_widen_what_a_client_sees(): void
    {
        $otherClient = Client::where('user_id', $this->otherClientUser->id)->firstOrFail();

        $attempts = [
            ['client' => $otherClient->id],
            ['client_id' => $otherClient->id],
            ['user' => $this->otherClientUser->id],
            ['user_id' => $this->otherClientUser->id],
            ['as' => $otherClient->id],
            // And the shotgun: every one of them at once.
            [
                'client' => $otherClient->id,
                'client_id' => $otherClient->id,
                'user' => $this->otherClientUser->id,
                'user_id' => $this->otherClientUser->id,
            ],
        ];

        foreach ($attempts as $query) {
            $label = json_encode($query);

            $this->actingAs($this->clientUser)
                ->get(route('client.projects.index', $query))
                ->assertOk()
                ->assertSee('PRJ-OURS')
                ->assertDontSee('PRJ-THEIRS');

            // And the same parameters cannot open the project itself.
            $this->actingAs($this->clientUser)
                ->get(route('client.projects.show', ['project' => $this->otherProject] + $query))
                ->assertNotFound("a client opened another client's project with {$label}");

            // Nor post a question onto it.
            $this->actingAs($this->clientUser)
                ->post(route('client.questions.store', ['project' => $this->otherProject] + $query), ['body' => 'Prying'])
                ->assertNotFound("a client wrote onto another client's project with {$label}");
        }
    }

    /* --------------------------- is_client_visible -------------------------- */

    public function test_a_milestone_without_the_flag_does_not_render(): void
    {
        $this->milestone($this->project, 'Shared sign-off', true);
        $this->milestone($this->project, 'Internal refactor window', false);

        $this->actingAs($this->clientUser)
            ->get(route('client.projects.show', $this->project))
            ->assertOk()
            ->assertSee('Shared sign-off')
            ->assertDontSee('Internal refactor window');
    }

    public function test_a_file_without_the_flag_neither_renders_nor_streams(): void
    {
        $shared = $this->file($this->project, 'Brand guidelines.pdf', true);
        $hidden = $this->file($this->project, 'Internal costings.pdf', false);

        $this->actingAs($this->clientUser)
            ->get(route('client.projects.show', $this->project))
            ->assertOk()
            ->assertSee('Brand guidelines.pdf')
            ->assertDontSee('Internal costings.pdf');

        // AND CANNOT BE FETCHED BY ID. The list not offering it is not the
        // control; the route refusing it is.
        $this->actingAs($this->clientUser)
            ->get(route('files.client', $hidden))
            ->assertNotFound();

        // And the flagged one really is served, so the 404 above is a refusal
        // and not simply an empty disk.
        $this->actingAs($this->clientUser)->get(route('files.client', $shared))->assertOk();
    }

    /** A flagged file on ANOTHER client's project is still theirs, not yours. */
    public function test_a_flagged_file_on_another_clients_project_is_refused(): void
    {
        $theirs = $this->file($this->otherProject, 'Their brand guidelines.pdf', true);

        $this->actingAs($this->clientUser)
            ->get(route('files.client', $theirs))
            ->assertNotFound();

        // Their own client is served it, so the refusal above is about WHOSE it
        // is rather than about the flag or the bytes.
        $this->actingAs($this->otherClientUser)
            ->get(route('files.client', $theirs))
            ->assertOk();
    }

    /**
     * A URL signed for one client does not serve another.
     *
     * The signature says WHO is asking and never that they may look: the
     * ownership check runs against whoever follows the link. The guards are
     * forgotten first, or the acting session would answer instead of the
     * signature and this would pass while proving nothing.
     */
    public function test_a_url_signed_for_one_client_does_not_serve_another(): void
    {
        $shared = $this->file($this->project, 'Brand guidelines.pdf', true);

        $mine = PrivateFiles::signedUrl('files.client', ['file' => $shared->id], $this->clientUser);
        $theirs = PrivateFiles::signedUrl('files.client', ['file' => $shared->id], $this->otherClientUser);

        $this->app['auth']->forgetGuards();
        $this->get($mine)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->get($theirs)->assertNotFound();

        // And with no identity at all, ResolveFileViewer refuses before the
        // controller is reached.
        $this->app['auth']->forgetGuards();
        $this->get(route('files.client', $shared))->assertNotFound();
    }

    /** The team route still refuses a client — the two questions stay separate. */
    public function test_the_team_file_route_still_refuses_a_client(): void
    {
        $shared = $this->file($this->project, 'Brand guidelines.pdf', true);

        $this->actingAs($this->clientUser)
            ->get(route('files.team', $shared))
            ->assertNotFound();
    }

    /* -------------------------- What must never appear ---------------------- */

    /**
     * No money, no client identity, no team, no member, no task, no comment.
     *
     * Checked on every client screen, against strings that appear nowhere by
     * accident.
     */
    public function test_nothing_internal_renders_on_any_client_screen(): void
    {
        $this->milestone($this->project, 'Shared sign-off', true);
        $this->file($this->project, 'Brand guidelines.pdf', true);

        $task = $this->makeTask($this->team, [
            'project_id' => $this->project->id,
            'title' => 'Refactor the Gormenghast parser',
        ]);
        $this->makeStint($task, $this->member, 3, $this->monday());

        $this->actingAs($this->lead)->post(
            route('admin.projects.comments.store', $this->project),
            ['body' => 'Sixpence none the richer — internal note'],
        )->assertSessionHasNoErrors();

        $forbidden = [
            // The contract value and its currency.
            '987654.32', '987,654', 'PKR',
            // Another client, and this one's own contact details.
            'Dunwoody Mercantile', 'Crispin Fallowfield', 'billing@example.test',
            // The team, its lead and its member.
            'Peregrine Web Squad', 'Ayesha Khan', 'Bilal Ahmed',
            // A task and a comment.
            'Refactor the Gormenghast parser', 'Sixpence none the richer',
        ];

        foreach ([route('client.projects.index'), route('client.projects.show', $this->project)] as $url) {
            $html = $this->actingAs($this->clientUser)->get($url)->assertOk()->getContent();

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $html, sprintf(
                    '"%s" reached a client screen (%s). A client sees their project, its milestones and '
                    .'the files marked for them — never the team, the work or the money.',
                    $needle,
                    $url,
                ));
            }
        }
    }

    /** The company name a client is shown is their OWN, which is not a leak. */
    public function test_a_client_does_see_their_own_company_name(): void
    {
        $this->actingAs($this->clientUser)
            ->get(route('client.projects.index'))
            ->assertOk()
            ->assertSee('Bartleby Ironworks PLC');
    }

    /* ------------------------------ The layout ------------------------------ */

    /**
     * No sidebar, no bell — and not because they are hidden.
     *
     * The client layout does not include components/admin/layout or the
     * sidebar at all, so there is no branch that could be got backwards.
     */
    public function test_the_client_layout_renders_no_sidebar_and_no_bell(): void
    {
        foreach ([route('client.projects.index'), route('client.projects.show', $this->project)] as $url) {
            $html = $this->actingAs($this->clientUser)->get($url)->assertOk()->getContent();

            // The sidebar's own class, the nav items' class, and the bell's
            // aria-label and its mark-all-read form.
            $this->assertStringNotContainsString('admin-sidebar', $html);
            $this->assertStringNotContainsString('nav-section-label', $html);
            $this->assertStringNotContainsString('aria-label="Notifications', $html);
            $this->assertStringNotContainsString(route('admin.notifications.read-all'), $html);
            $this->assertStringNotContainsString('See all notifications', $html);

            // And it is the client shell that rendered, not nothing at all.
            $this->assertStringContainsString('Client Portal', $html);
            $this->assertStringContainsString(route('logout'), $html);
        }
    }

    /** A client screen references no admin route at all. */
    public function test_no_client_screen_links_into_the_admin_panel(): void
    {
        foreach ([route('client.projects.index'), route('client.projects.show', $this->project)] as $url) {
            $html = $this->actingAs($this->clientUser)->get($url)->assertOk()->getContent();

            $this->assertDoesNotMatchRegularExpression('#href="[^"]*/admin/#', $html, sprintf(
                'A client screen (%s) links into the admin panel, which would answer them with a 403.',
                $url,
            ));
        }
    }
}
