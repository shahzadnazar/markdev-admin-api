<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The academy and the team portal are separate jobs that share a login.
 *
 * A team lead is not a junior manager, and an instructor is not a junior team
 * member. Somebody who does both holds both roles and gets the union — that is
 * a decision made per person, never baked into a role.
 *
 * TESTED IN BOTH DIRECTIONS, and at two levels: the permission matrix, so a
 * crossing grant added to the seeder fails here rather than in production, and
 * the screens themselves, so the gate on the way in is doing the same job the
 * matrix describes.
 *
 * THIS TEST IS NOW THE ONLY THING KEEPING THE TWO SIDES APART. Both route
 * groups were widened to admit permissions as well as role names, so that a
 * custom role holding `teams.view` is not refused at a door it was granted the
 * key to. The consequence is that the door no longer refuses an academy role
 * that was given a team permission by mistake — the matrix assertions below
 * are what catch that now. Weakening them is not a test change; it is removing
 * the separation.
 */
class TeamRoleSeparationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every academy module. A team role holding any of these is the bug.
     *
     * @var array<int, string>
     */
    protected const ACADEMY_MODULES = [
        'dashboard', 'users', 'students', 'roles', 'categories', 'courses',
        'lessons', 'notes', 'enrollments', 'assignments', 'quizzes',
        'attendance', 'devices', 'certificates', 'announcements', 'billing',
        'help', 'reports', 'audit-logs', 'settings', 'backups', 'notifications',
    ];

    /**
     * Every team-portal module. An academy role holding any of these is the bug.
     *
     * @var array<int, string>
     */
    protected const TEAM_MODULES = [
        'teams', 'projects', 'tasks', 'clients', 'task-statuses', 'project-statuses',
        // Phase 8. Their OWN modules rather than the academy's `dashboard` and
        // `reports` with a scope bolted on — reusing those would have handed a
        // team-lead the academy dashboard and its five exports.
        'team-dashboard', 'team-reports',
    ];

    /** Roles that do client work and nothing else. */
    protected const TEAM_ROLES = ['team-lead', 'team', 'client'];

    /** Roles that run the academy and nothing else. */
    protected const ACADEMY_ROLES = ['manager', 'instructor', 'student'];

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

    /** The module a permission belongs to: everything before the first dot. */
    protected function moduleOf(string $permission): string
    {
        return str_contains($permission, '.')
            ? substr($permission, 0, strpos($permission, '.'))
            : $permission;
    }

    /* ---------------------------- The matrix ------------------------------- */

    /**
     * Both lists above cover every permission there is.
     *
     * Without this the directional tests below quietly stop covering anything
     * new: a module named in neither list would be waved through in both
     * directions, which is exactly the shape of the mistake they exist to catch.
     */
    public function test_every_permission_belongs_to_the_academy_or_the_team_portal(): void
    {
        $known = array_merge(static::ACADEMY_MODULES, static::TEAM_MODULES);

        $unclassified = Permission::pluck('name')
            ->reject(fn (string $name) => in_array($this->moduleOf($name), $known, true))
            ->values()
            ->all();

        $this->assertSame([], $unclassified, implode("\n", [
            'These permissions belong to neither list in '.static::class.':',
            '  '.implode(', ', $unclassified),
            'Add each module to ACADEMY_MODULES or TEAM_MODULES, or the separation',
            'tests below stop covering it in both directions.',
        ]));
    }

    public function test_no_team_role_holds_an_academy_permission(): void
    {
        foreach (static::TEAM_ROLES as $role) {
            $academy = Role::findByName($role, 'web')->permissions->pluck('name')
                ->filter(fn (string $name) => in_array($this->moduleOf($name), static::ACADEMY_MODULES, true))
                ->values()
                ->all();

            $this->assertSame([], $academy, sprintf(
                'The "%s" role holds academy permissions: %s. Team roles run client work, not the academy — '
                .'somebody who does both holds both roles instead.',
                $role,
                implode(', ', $academy),
            ));
        }
    }

    public function test_no_academy_role_holds_a_team_permission(): void
    {
        foreach (static::ACADEMY_ROLES as $role) {
            $team = Role::findByName($role, 'web')->permissions->pluck('name')
                ->filter(fn (string $name) => in_array($this->moduleOf($name), static::TEAM_MODULES, true))
                ->values()
                ->all();

            $this->assertSame([], $team, sprintf(
                'The "%s" role holds team-portal permissions: %s. An instructor is not a junior team member.',
                $role,
                implode(', ', $team),
            ));
        }
    }

    /** Only the two roles that run everything hold both sides. */
    public function test_super_admin_and_admin_hold_both_sides(): void
    {
        foreach (['super-admin', 'admin'] as $role) {
            $held = Role::findByName($role, 'web')->permissions->pluck('name');

            $this->assertTrue($held->contains('teams.view'), "{$role} should hold teams.view");
            $this->assertTrue($held->contains('students.view'), "{$role} should hold students.view");
            $this->assertTrue($held->contains('task-statuses.manage'), "{$role} should hold task-statuses.manage");
            $this->assertTrue($held->contains('project-statuses.manage'), "{$role} should hold project-statuses.manage");
        }
    }

    /* ---------------------------- The screens ------------------------------ */

    public static function academyScreens(): array
    {
        return [
            'dashboard' => ['admin.dashboard'],
            'students' => ['admin.students.index'],
            'users' => ['admin.users.index'],
            'courses' => ['admin.courses.index'],
            'attendance register' => ['admin.attendance.daily'],
            'settings' => ['admin.settings.edit'],
            'attendance slots' => ['admin.attendance-slots.index'],
        ];
    }

    /** @dataProvider academyScreens */
    public function test_a_team_lead_cannot_reach_an_academy_screen(string $route): void
    {
        $this->actingAs($this->user('team-lead'))->get(route($route))->assertForbidden();
    }

    /** @dataProvider academyScreens */
    public function test_a_team_member_cannot_reach_an_academy_screen(string $route): void
    {
        $this->actingAs($this->user('team'))->get(route($route))->assertForbidden();
    }

    public static function teamScreens(): array
    {
        return [
            'teams list' => ['admin.teams.index'],
            'new team' => ['admin.teams.create'],
            'projects list' => ['admin.projects.index'],
            'new project' => ['admin.projects.create'],
            'clients list' => ['admin.clients.index'],
            'new client' => ['admin.clients.create'],
            'task list' => ['admin.tasks.index'],
            'board' => ['admin.tasks.board'],
            'new task' => ['admin.tasks.create'],
            'my attendance' => ['admin.team-attendance.mine'],
            'my leave' => ['admin.team-leave.mine'],
            'my fines' => ['admin.team-fines.mine'],
            'team register' => ['admin.team-attendance.index'],
            'leave review' => ['admin.team-leave.index'],
            'absence ledger' => ['admin.team-fines.index'],
            'cross-team channel' => ['admin.team-channel.index'],
        ];
    }

    /** @dataProvider teamScreens */
    public function test_an_instructor_cannot_reach_a_team_screen(string $route): void
    {
        $this->actingAs($this->user('instructor'))->get(route($route))->assertForbidden();
    }

    /** @dataProvider teamScreens */
    public function test_a_manager_cannot_reach_a_team_screen(string $route): void
    {
        // Managers run the academy, not client work. Refused at the door of the
        // team group, not merely at each route's own permission.
        $this->actingAs($this->user('manager'))->get(route($route))->assertForbidden();
    }

    public function test_a_client_reaches_nothing_in_the_admin_panel(): void
    {
        $client = $this->user('client');

        foreach (['admin.dashboard', 'admin.teams.index', 'admin.settings.edit'] as $route) {
            $this->actingAs($client)->get(route($route))->assertForbidden();
        }
    }

    /**
     * EVERY /admin route, derived from the router — including this phase's.
     *
     * The three named above were a sample, and a sample is what phase 7 could
     * have walked straight past: it gave a client their own portal and a login
     * that lands somewhere, so "a client reaches no admin screen" stopped being
     * a fact about a role with nowhere to go and became a fact that has to keep
     * being true. Derived rather than listed, so a route added in a later phase
     * is covered the day it appears.
     *
     * Every verb, not only GET. A POST is how a client would actually reach past
     * a screen — answering a question they were not asked, marking a file
     * client-visible — and those are the routes this phase added.
     *
     * 403 or 404 both count, and 405 for a verb a route does not take: what must
     * never happen is a 2xx or a redirect, either of which means they got in.
     * A parameterised route is called with a 1, which is either a row they may
     * not see or no row at all; both are refusals and neither is admittance.
     */
    public function test_a_client_is_refused_by_every_single_admin_route(): void
    {
        $client = $this->user('client');
        $admitted = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! str_starts_with($name, 'admin.')) {
                continue;
            }

            $url = '/'.preg_replace('/\{\w+\??\}/', '1', $route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $status = $this->actingAs($client)
                    ->call($method, $url)
                    ->getStatusCode();

                $checked++;

                if (! in_array($status, [403, 404, 405], true)) {
                    $admitted[] = sprintf('%s %s (%s) answered %d', $method, $url, $name, $status);
                }
            }
        }

        $this->assertSame([], $admitted, implode("\n", [
            'A client was admitted to the admin panel:',
            '  '.implode("\n  ", $admitted),
            'The client portal is a separate group with its own layout. A client reaches NO /admin route,',
            'and that is what keeps every panel view free of an "unless they are a client" branch.',
        ]));

        // Asserted, not assumed: a loop that found no routes would make the
        // emptiness above meaningless and pass while blind.
        $this->assertGreaterThan(150, $checked, 'the sweep barely visited any admin routes');
    }

    /**
     * And the client portal refuses everybody who is not a client.
     *
     * The other direction of the same wall. A team-lead has no business on a
     * client's screens either — not because they would learn anything new about
     * that project, but because "staff can open the client view" is how a
     * client-facing page quietly becomes a staff page with extra fields.
     */
    public function test_the_client_portal_refuses_every_staff_role(): void
    {
        foreach (['super-admin', 'admin', 'manager', 'instructor', 'team-lead', 'team', 'student'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('client.projects.index'))
                ->assertForbidden(sprintf('a %s reached the client portal', $role));
        }
    }

    /**
     * The client book is admin-only, on both sides of the wall.
     *
     * A team lead runs client work and still never learns whose it is: that is
     * what the project code is for. Asserted separately from the academy tests
     * because this refusal is inside the team portal, not across the wall.
     */
    public function test_no_team_role_reaches_the_client_book(): void
    {
        foreach (['team-lead', 'team'] as $role) {
            $user = $this->user($role);

            foreach (['admin.clients.index', 'admin.clients.create'] as $route) {
                $this->actingAs($user)->get(route($route))->assertForbidden();
            }
        }
    }

    /** Nor the project forms, which have to offer the client list to work. */
    public function test_no_team_role_reaches_a_project_form(): void
    {
        foreach (['team-lead', 'team'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('admin.projects.create'))
                ->assertForbidden();
        }
    }

    /* ------------------------- What the roles CAN do ------------------------ */

    public function test_a_team_lead_reaches_the_teams_screen(): void
    {
        // The other half of the same rule: separation is not "team roles can do
        // nothing", it is "team roles do team work".
        $this->actingAs($this->user('team-lead'))
            ->get(route('admin.teams.index'))
            ->assertOk()
            ->assertSee('Teams');
    }

    public function test_a_team_lead_cannot_create_or_delete_a_team(): void
    {
        $lead = $this->user('team-lead');

        $this->actingAs($lead)->get(route('admin.teams.create'))->assertForbidden();
        $this->actingAs($lead)->post(route('admin.teams.store'), [])->assertForbidden();
    }

    public function test_only_an_admin_manages_the_status_lists(): void
    {
        $this->actingAs($this->user('team-lead'))
            ->get(route('admin.task-statuses.index'))
            ->assertForbidden();

        $this->actingAs($this->user('manager'))
            ->get(route('admin.task-statuses.index'))
            ->assertForbidden();

        $admin = $this->user('admin');
        $this->actingAs($admin)->get(route('admin.task-statuses.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.project-statuses.index'))->assertOk();
    }
}
