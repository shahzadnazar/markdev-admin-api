<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\User;
use App\Support\PortalHome;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
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
            // Phase 2 shipped admin.projects.index, so a team member now
            // lands on their project list instead of the no-portal page.
            'team' => ['team', 'admin.projects.index'],
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

    /**
     * The "Dashboard" breadcrumb, per role.
     *
     * Twenty-nine views draw that crumb and all of them now ask PortalHome::url()
     * instead of naming `admin.dashboard`, so this is where the answer is pinned.
     *
     * @dataProvider landings
     */
    public function test_the_dashboard_crumb_points_at_that_roles_own_landing(string $role, string $expected): void
    {
        $this->assertSame(route($expected), PortalHome::url($this->user($role)));
    }

    /**
     * THE CLAIM THE SWEEP RESTS ON, checked rather than assumed.
     *
     * Pointing every crumb at PortalHome was only safe if it resolves to exactly
     * what an academy role has today. It does, and for a structural reason:
     * `dashboard.view` is first in DESTINATIONS, and every role the academy route
     * group admits holds it. Written down as a test because "it works out to the
     * same thing" is the kind of claim that stops being true quietly.
     */
    public function test_the_crumb_is_unchanged_for_every_academy_role(): void
    {
        foreach (['super-admin', 'admin', 'manager', 'instructor'] as $role) {
            $this->assertSame(
                route('admin.dashboard'),
                PortalHome::url($this->user($role)),
                "the {$role} crumb moved, and it was not supposed to",
            );
        }
    }

    /** And for a team role it does NOT, which is the whole point of the sweep. */
    public function test_the_crumb_moves_for_a_team_role(): void
    {
        $lead = $this->user('team-lead');
        $member = $this->user('team');

        $this->assertNotSame(route('admin.dashboard'), PortalHome::url($lead));
        $this->assertNotSame(route('admin.dashboard'), PortalHome::url($member));

        // And it opens for them, which route('admin.dashboard') did not.
        $this->actingAs($lead)->get(PortalHome::url($lead))->assertOk();
        $this->actingAs($member)->get(PortalHome::url($member))->assertOk();
        $this->actingAs($lead)->get(route('admin.dashboard'))->assertForbidden();
    }

    /** Called with nothing, it reads the guard — which is how a view calls it. */
    public function test_url_reads_the_signed_in_user_when_given_nothing(): void
    {
        $lead = $this->user('team-lead');

        $this->actingAs($lead);

        $this->assertSame(route('admin.teams.index'), PortalHome::url());
    }

    /**
     * A client with a CLIENT RECORD lands on their portal.
     *
     * The provider above keeps saying NONE for `client`, and that is not stale:
     * it describes a client ROLE with nothing linked to it, which is an account
     * an admin has set up and not yet finished. The entitlement is the row.
     */
    public function test_a_linked_client_lands_on_the_client_portal(): void
    {
        $user = $this->user('client');

        $this->assertSame(PortalHome::NONE, PortalHome::for($user));

        Client::create(['name' => 'Bartleby Ironworks', 'user_id' => $user->id, 'is_active' => true]);

        $this->assertSame(PortalHome::CLIENT_DESTINATION, PortalHome::for($user->fresh()));
        $this->assertSame(route('client.projects.index'), PortalHome::url($user->fresh()));

        $this->actingAs($user)->get('/')->assertRedirect(route('client.projects.index'));
    }

    /**
     * Deactivating a client does not take their portal away.
     *
     * The toggle means "stop offering them on new projects" — phase 2 says so in
     * as many words, and their existing projects stay readable. The switch that
     * means "may not sign in" is the USER's own is_active, and it is the one
     * switch that means that everywhere.
     */
    public function test_a_deactivated_client_still_has_a_portal(): void
    {
        $user = $this->user('client');
        Client::create(['name' => 'Bartleby Ironworks', 'user_id' => $user->id, 'is_active' => false]);

        $this->assertSame(PortalHome::CLIENT_DESTINATION, PortalHome::for($user->fresh()));
    }

    /**
     * THE CLIENT DESTINATION IS NOT IN THE PANEL GATE.
     *
     * gate() is derived from DESTINATIONS and is the door to /admin. That is the
     * whole reason the client destination is resolved from data rather than from
     * a permission in that map: a permission there would be a permission that
     * opens the admin topbar, which is the one thing this phase exists to keep
     * from a client.
     */
    public function test_the_client_destination_is_not_one_of_the_panel_permissions(): void
    {
        $this->assertNotContains(PortalHome::CLIENT_DESTINATION, PortalHome::DESTINATIONS);
        $this->assertStringNotContainsString('client', PortalHome::gate());

        $user = $this->user('client');
        Client::create(['name' => 'Bartleby Ironworks', 'user_id' => $user->id, 'is_active' => true]);

        // Landing somewhere did not open the topbar.
        $this->actingAs($user->fresh())->get(route('admin.notifications.index'))->assertForbidden();
        $this->actingAs($user->fresh())->post(route('admin.notifications.read-all'))->assertForbidden();
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
     * THE SEAM IS CLOSED. Every destination now has a screen.
     *
     * The assertion that used to say `admin.tasks.index` did not exist has
     * been retired: phase 3 shipped it, which was the whole point of writing
     * the entry before the screen. What replaces it is the guard that outlives
     * the seam — a destination added to the map with no route behind it would
     * make PortalHome skip it silently, and this is where that shows up.
     *
     * Route::has is still consulted at run time, and still should be: it is
     * what makes the next phase's entry safe to write early.
     */
    public function test_every_destination_now_has_a_screen(): void
    {
        foreach (PortalHome::DESTINATIONS as $permission => $route) {
            $this->assertTrue(Route::has($route), sprintf(
                'PortalHome sends someone holding "%s" to %s, and no such route exists. Either the phase '
                .'that owns that screen has not shipped — in which case this entry is waiting, and that is '
                .'fine — or the route was renamed and the map was not.',
                $permission,
                $route,
            ));
        }
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

    /* ------------------- Destinations a custom role can open ---------------- */

    /**
     * Holding a destination's permission is enough to get through its door.
     *
     * PortalHome asks Route::has, which says whether a route EXISTS — not
     * whether this person survives its middleware. The Roles & Permissions
     * screen lets a super-admin build a role holding, say, `teams.view` and
     * nothing else; that role is in none of the groups' role lists, so it was
     * refused at the door and handed exactly the 403 this resolver removed.
     * The eight seeded roles cannot show it, because every one of them is
     * named in a group.
     *
     * Derived from DESTINATIONS so a phase that adds an entry is covered by
     * this test without anyone remembering to come back here.
     *
     * @dataProvider destinations
     */
    public function test_a_custom_role_holding_only_one_destination_permission_can_open_it(string $permission): void
    {
        $role = Role::create(['name' => 'only-'.$permission, 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole($role);

        $landing = PortalHome::for($user);

        $this->actingAs($user)->get(route($landing))->assertOk(sprintf(
            'A role holding only "%s" was refused at %s. The route exists, so PortalHome sent them '
            .'there, but the group gate does not admit the permission — only the role names beside '
            .'it. Add the permission to that group\'s role_or_permission list.',
            $permission,
            $landing,
        ));
    }

    /** @return array<string, array{string}> */
    public static function destinations(): array
    {
        return collect(PortalHome::DESTINATIONS)
            ->keys()
            ->mapWithKeys(fn (string $permission) => [$permission => [$permission]])
            ->all();
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
