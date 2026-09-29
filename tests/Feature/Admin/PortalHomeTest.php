<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\PortalHome;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Nobody is answered with a 403 for signing in successfully.
 *
 * Every role either lands on a screen it can open, or on the no-portal page —
 * which is a 200 saying the account is fine and an administrator has to finish
 * setting it up. The landing is asserted through the LOGIN FORM, not just by
 * hitting the route, because the hop this fixes is the one Breeze makes.
 */
class PortalHomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * Every role in the seeder, and where it belongs today.
     *
     * `team` and `client` are on the no-portal page on purpose: the team member
     * holds projects.view and tasks.view, and neither screen exists until
     * phase 2. `student` is here because a student can type this app's login
     * form even though the portal is where they belong — they used to get a 403
     * for it.
     */
    public static function landings(): array
    {
        return [
            'super-admin' => ['super-admin', 'admin.dashboard'],
            'admin' => ['admin', 'admin.dashboard'],
            'manager' => ['manager', 'admin.dashboard'],
            'instructor' => ['instructor', 'admin.dashboard'],
            'team-lead' => ['team-lead', 'admin.teams.index'],
            'team' => ['team', PortalHome::NONE],
            'client' => ['client', PortalHome::NONE],
            'student' => ['student', PortalHome::NONE],
        ];
    }

    /** @dataProvider landings */
    public function test_signing_in_lands_somewhere_that_is_not_a_403(string $role, string $expected): void
    {
        $user = $this->user($role);

        $login = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);

        // Breeze hands back route('dashboard'); that name is the single hop
        // this change fixes, which is why Breeze itself is untouched.
        $login->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertRedirect(route($expected));
        $this->get(route($expected))->assertOk();
    }

    /** @dataProvider landings */
    public function test_the_root_route_agrees_with_the_login_flow(string $role, string $expected): void
    {
        $this->actingAs($this->user($role))->get('/')->assertRedirect(route($expected));
    }

    /** @dataProvider landings */
    public function test_the_resolver_answers_for_every_role(string $role, string $expected): void
    {
        $this->assertSame($expected, PortalHome::for($this->user($role)));
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->assertSame(PortalHome::GUEST, PortalHome::for(null));

        $this->get('/')->assertRedirect(route('login'));
    }

    /**
     * The framework's own guest middleware resolves the `dashboard` NAME too.
     *
     * Asserted rather than assumed: it is the claim that lets one route cover
     * an already-signed-in visitor opening /login as well as the seven Breeze
     * controllers.
     */
    public function test_an_already_signed_in_visitor_opening_login_goes_to_their_own_portal(): void
    {
        $this->actingAs($this->user('team-lead'))
            ->get('/login')
            ->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertRedirect(route('admin.teams.index'));
    }

    /* --------------------------- The phase seam ---------------------------- */

    /**
     * A destination whose screen has not shipped is skipped, not returned.
     *
     * This is what makes phases 2 to 7 inherit the fix: the entries are already
     * in the map, and each lights up on its own the day its route exists. If
     * this test starts failing because the routes are there, delete it and move
     * `team` in the landings above to its new screen — that is the whole change.
     */
    public function test_destinations_whose_screens_do_not_exist_yet_are_skipped(): void
    {
        $this->assertArrayHasKey('projects.view', PortalHome::DESTINATIONS);
        $this->assertArrayHasKey('tasks.view', PortalHome::DESTINATIONS);

        $this->assertFalse(Route::has('admin.projects.index'), 'Phase 2 has shipped — see this test.');
        $this->assertFalse(Route::has('admin.tasks.index'), 'Phase 2 has shipped — see this test.');

        $member = $this->user('team');

        $this->assertTrue($member->can('projects.view'));
        $this->assertSame(PortalHome::NONE, PortalHome::for($member));
    }

    /** The academy wins for the two roles that hold everything. */
    public function test_the_order_puts_the_academy_first(): void
    {
        $this->assertSame(
            'dashboard.view',
            array_key_first(PortalHome::DESTINATIONS),
            'Super-admin and admin hold every permission, so the academy has to be first.',
        );

        $admin = $this->user('super-admin');

        $this->assertTrue($admin->can('teams.view'));
        $this->assertSame('admin.dashboard', PortalHome::for($admin));
    }

    /* -------------------------- The no-portal page -------------------------- */

    public function test_the_no_portal_page_says_the_account_works(): void
    {
        $client = $this->user('client');

        $this->actingAs($client)
            ->get(route(PortalHome::NONE))
            ->assertOk()
            ->assertSee('Your account is active', false)
            ->assertSee('Sign out')
            // Named so the person can quote it and whoever they ask can see
            // what to grant.
            ->assertSee('client');
    }

    public function test_the_no_portal_page_needs_a_login(): void
    {
        $this->get(route(PortalHome::NONE))->assertRedirect(route('login'));
    }
}
