<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Support\MaintenanceMode;
use App\Support\PortalHome;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsSettingsPayload;
use Tests\TestCase;

/**
 * Downtime that actually holds somebody out.
 *
 * It was a setting, a toggle and a banner reading "students currently see a
 * downtime notice" while nothing blocked anybody — a control that did nothing
 * and a sentence that was not true. The tests here are mostly about the two
 * halves of that: who is held out, and what the status code is.
 */
class MaintenanceModeTest extends TestCase
{
    // The settings page is ONE form, so a partial body fails validation on
    // fields this file is not about. The shared builder exists because five
    // classes had each grown their own copy.
    use BuildsSettingsPayload, RefreshDatabase;

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

    protected function turnOn(?string $message = null): void
    {
        Setting::updateOrCreate(['key' => MaintenanceMode::SWITCH_KEY], ['value' => true, 'group' => 'general']);

        if ($message !== null) {
            Setting::updateOrCreate(['key' => MaintenanceMode::MESSAGE_KEY], ['value' => $message, 'group' => 'general']);
        }

        Setting::forgetCached();
    }

    /** A client login with a client record, so the portal is theirs to be held out of. */
    protected function client(): User
    {
        $user = $this->user('client');
        Client::create(['name' => 'Bartleby Ironworks', 'user_id' => $user->id, 'is_active' => true]);

        return $user;
    }

    /* -------------------------------- It is off ----------------------------- */

    public function test_with_it_off_nothing_changes_for_anyone(): void
    {
        $student = $this->user('student');
        $client = $this->client();

        Sanctum::actingAs($student);
        $this->getJson('/api/v1/dashboard')->assertOk();

        $this->actingAs($client)->get(route('client.projects.index'))->assertOk();

        foreach (PortalHome::PANEL_ROLES as $role) {
            $staff = $this->user($role);
            $this->actingAs($staff)->get(route(PortalHome::for($staff)))->assertOk();
        }
    }

    /* -------------------------------- It is on ------------------------------ */

    public function test_a_students_api_call_gets_a_503_carrying_the_message(): void
    {
        $student = $this->user('student');
        $this->turnOn('Migrating the gradebook — back by 6pm.');

        Sanctum::actingAs($student);

        $this->getJson('/api/v1/dashboard')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Migrating the gradebook — back by 6pm.')
            ->assertJsonPath('maintenance', true)
            ->assertHeader('Retry-After');
    }

    public function test_a_client_gets_the_503_page(): void
    {
        $client = $this->client();
        $this->turnOn('Migrating the gradebook — back by 6pm.');

        $this->actingAs($client)
            ->get(route('client.projects.index'))
            ->assertStatus(503)
            ->assertSee('Migrating the gradebook — back by 6pm.')
            // escape: false, because this one is STATIC markup in the template
            // and reaches the page with its apostrophe intact, while the
            // message above goes through {{ }} and is escaped. The needle has
            // to match how the page produced the text — the same trap that made
            // SidebarProfileLinkTest fail at random on faker names.
            ->assertSee("We'll be back shortly", escape: false);
    }

    /**
     * EVERY staff role still reaches their own portal.
     *
     * Their own, resolved by PortalHome — an instructor's dashboard and a team
     * member's project list are different screens, and "staff are unaffected"
     * has to mean both of them.
     */
    public function test_every_staff_role_still_reaches_their_own_portal(): void
    {
        $this->turnOn();

        foreach (PortalHome::PANEL_ROLES as $role) {
            $staff = $this->user($role);

            $this->actingAs($staff)
                ->get(route(PortalHome::for($staff)))
                ->assertOk("a {$role} was held out of their own portal by maintenance mode");
        }
    }

    /**
     * The verdict per role, written down.
     *
     * MaintenanceMode::allows reuses PortalHome::PANEL_ROLES rather than keeping
     * a second list — "has a staff panel" and "keeps working during maintenance"
     * are the same question about the same people. This is what makes that reuse
     * safe: if the two ever need to differ, this fails by name rather than
     * drifting quietly.
     */
    public function test_who_is_blocked_and_who_is_not(): void
    {
        $this->turnOn();

        $expected = [
            'super-admin' => false,
            'admin' => false,
            'manager' => false,
            'instructor' => false,
            'team-lead' => false,
            'team' => false,
            'student' => true,
            'client' => true,
        ];

        foreach ($expected as $role => $blocked) {
            $this->assertSame(
                $blocked,
                MaintenanceMode::blocks($this->user($role)),
                "the verdict for a {$role} moved",
            );
        }

        // Default-deny: nobody signed in, and a role nobody has classified, are
        // both held out. A role added in a later phase is out until somebody
        // decides it is staff.
        $this->assertTrue(MaintenanceMode::blocks(null));
        $this->assertTrue(MaintenanceMode::blocks(User::factory()->create()));
    }

    /* ------------------------ Not locking yourself out ---------------------- */

    public function test_the_login_form_still_works_with_it_on(): void
    {
        $admin = $this->user('admin');
        $this->turnOn();

        $this->get(route('login'))->assertOk();

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    /** A student can still sign in; the block is met on the first real call. */
    public function test_the_api_login_still_works_with_it_on(): void
    {
        $student = $this->user('student');
        $this->turnOn();

        $this->postJson('/api/v1/auth/login', [
            'email' => $student->email,
            'password' => 'password',
        ])->assertOk();
    }

    public function test_an_admin_can_turn_it_off_again_while_it_is_on(): void
    {
        $admin = $this->user('super-admin');
        $this->turnOn('Back by 6pm.');

        // The settings screen opens, and the banner is on it.
        $this->actingAs($admin)
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('Maintenance mode is on');

        $this->actingAs($admin)->put('/admin/settings', $this->settingsPayload([
            'maintenance_mode' => 0,
        ]))->assertSessionHasNoErrors();

        Setting::forgetCached();

        $this->assertFalse(MaintenanceMode::isOn());
        $this->assertFalse(MaintenanceMode::blocks($this->user('student')));
    }

    public function test_an_admin_turning_it_on_is_not_locked_out(): void
    {
        $admin = $this->user('super-admin');

        $this->actingAs($admin)->put('/admin/settings', $this->settingsPayload([
            'maintenance_mode' => 1,
            'maintenance_message' => 'Migrating the gradebook.',
        ]))->assertSessionHasNoErrors();

        Setting::forgetCached();

        $this->assertTrue(MaintenanceMode::isOn());
        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertOk();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    }

    /* -------------------------------- The message --------------------------- */

    public function test_the_message_is_the_admins_text_once_they_set_one(): void
    {
        $admin = $this->user('super-admin');
        $student = $this->user('student');

        // Nothing typed: the default, and it is not hardcoded in a view.
        $this->turnOn();
        $this->assertSame(MaintenanceMode::DEFAULT_MESSAGE, MaintenanceMode::message());

        Sanctum::actingAs($student);
        $this->getJson('/api/v1/dashboard')
            ->assertStatus(503)
            ->assertJsonPath('message', MaintenanceMode::DEFAULT_MESSAGE);

        // Typed on the form, and it is what a student now reads.
        $this->actingAs($admin)->put('/admin/settings', $this->settingsPayload([
            'maintenance_mode' => 1,
            'maintenance_message' => 'Swapping the payment gateway. Back at 6pm.',
        ]))->assertSessionHasNoErrors();

        Setting::forgetCached();

        $this->assertSame('Swapping the payment gateway. Back at 6pm.', MaintenanceMode::message());

        Sanctum::actingAs($student);
        $this->getJson('/api/v1/dashboard')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Swapping the payment gateway. Back at 6pm.');
    }

    /** Whitespace is not a message: "nothing typed" is one value, not several. */
    public function test_a_blank_message_falls_back_to_the_default(): void
    {
        $this->turnOn('   ');

        $this->assertSame(MaintenanceMode::DEFAULT_MESSAGE, MaintenanceMode::message());
    }

    /* -------------------------------- The banner ---------------------------- */

    /**
     * The banner says what the middleware does.
     *
     * It said "students currently see a downtime notice" while nothing blocked
     * anybody, and would have been wrong a second way the day the block shipped,
     * because clients are held out too.
     */
    public function test_the_banner_matches_what_the_middleware_actually_does(): void
    {
        $admin = $this->user('super-admin');
        $this->turnOn();

        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('students and clients are being held out', $html);
        $this->assertStringContainsString('Staff are unaffected', $html);

        // The two claims it makes, checked against the middleware rather than
        // against another string.
        $this->assertTrue(MaintenanceMode::blocks($this->user('student')));
        $this->assertTrue(MaintenanceMode::blocks($this->client()));
        $this->assertFalse(MaintenanceMode::blocks($admin));
    }

    public function test_the_banner_is_absent_when_it_is_off(): void
    {
        $admin = $this->user('super-admin');

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Maintenance mode is on');
    }
}
