<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\ClientController;
use App\Models\Client;
use App\Models\User;
use App\Support\PortalHome;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Creating a client and its login on ONE screen.
 *
 * A client needs two rows — a `users` row holding the `client` role and a
 * `clients` row pointing at it — and until now an admin made them on two
 * screens that did not mention each other, then went to a third to use them.
 * The project form's client dropdown stayed silently empty until all of it was
 * done. A real user lost twenty minutes there and concluded the app was broken.
 *
 * The two halves this file is really about are the ones that would be quiet if
 * they broke: that the transaction makes a half-made client impossible, and that
 * the role handed out is `client` and nothing else. A screen that creates `users`
 * rows is exactly where an unintended role slips in.
 */
class ClientPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    /** The company fields, so each test only says what it is about. */
    protected function company(array $extra = []): array
    {
        return array_merge([
            'name' => 'Zephyrine Quartermain',
            'company' => 'Bartleby Ironworks PLC',
            'is_active' => 1,
        ], $extra);
    }

    /** The portal half, valid. */
    protected function portal(array $extra = []): array
    {
        return array_merge([
            'portal_name' => 'Zephyrine Quartermain',
            'portal_email' => 'zephyrine@bartleby-ironworks.test',
            'portal_password' => 'a-long-enough-password',
            'portal_password_confirmation' => 'a-long-enough-password',
        ], $extra);
    }

    /* ------------------------------ One screen ------------------------------ */

    public function test_the_client_form_creates_the_login_links_it_and_that_person_can_sign_in(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.clients.store'), $this->company() + $this->portal())
            ->assertRedirect(route('admin.clients.index'));

        $client = Client::sole();
        $user = User::where('email', 'zephyrine@bartleby-ironworks.test')->sole();

        $this->assertSame($user->getKey(), $client->user_id, 'The two rows were made but not linked.');
        $this->assertTrue($user->is_active, 'An inactive account cannot sign in, which is the only reason to fill these fields in.');
        $this->assertTrue(Hash::check('a-long-enough-password', $user->password), 'The password was stored unusable.');

        // EXACTLY the client role. Not "includes client" — the whole set.
        $this->assertSame([ClientController::PORTAL_ROLE], $user->getRoleNames()->all());

        // And then the thing the twenty minutes were for: they can actually get
        // in. Signed out first — the login route turns an already-authenticated
        // visitor away, so posting to it as the admin would prove nothing.
        $this->post(route('logout'));
        $this->assertGuest();

        $this->post(route('login'), [
            'email' => 'zephyrine@bartleby-ironworks.test',
            'password' => 'a-long-enough-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        // /dashboard is the one hop every Breeze controller redirects to, and it
        // asks PortalHome where this person belongs. PortalHome resolves a client
        // by DATA -- the clients row pointing at this account -- so arriving at
        // the portal is also the link being right.
        $this->get(route('dashboard'))->assertRedirect(route(PortalHome::CLIENT_DESTINATION));
        $this->get(route(PortalHome::CLIENT_DESTINATION))->assertOk();
    }

    /**
     * A company with no login is a real case, not an oversight.
     *
     * Work often starts before the client is given access, so leaving the
     * section blank has to save cleanly rather than nag.
     */
    public function test_leaving_the_portal_fields_blank_creates_no_user(): void
    {
        $before = User::count();

        $this->actingAs($this->admin)
            ->post(route('admin.clients.store'), $this->company())
            ->assertRedirect(route('admin.clients.index'));

        $this->assertSame($before, User::count(), 'A blank Portal access section must not create an account.');
        $this->assertNull(Client::sole()->user_id);
    }

    /* ------------------------------- Refusals ------------------------------- */

    /**
     * A duplicate email is refused and NOTHING is written.
     *
     * The orphan this is about is a `clients` row with no login: saved, looking
     * finished, and useless. Validation stops it before any write here, and the
     * transaction stops it when a write fails — see the atomicity test below.
     */
    public function test_a_duplicate_email_is_refused_and_leaves_no_orphan_client(): void
    {
        $existing = User::factory()->create(['email' => 'taken@bartleby-ironworks.test']);

        $this->actingAs($this->admin)
            ->post(route('admin.clients.store'), $this->company() + $this->portal(['portal_email' => 'taken@bartleby-ironworks.test']))
            ->assertSessionHasErrors('portal_email');

        $this->assertSame(0, Client::count(), 'A refused login must not leave a client behind.');
        $this->assertSame(1, User::where('email', 'taken@bartleby-ironworks.test')->count());
        $this->assertFalse($existing->fresh()->hasRole(ClientController::PORTAL_ROLE), 'The existing account must not be co-opted.');
    }

    /**
     * ATOMIC, and this is the test that proves it.
     *
     * The client is written first and the login second, so a login that fails
     * leaves a company row behind unless the transaction removes it. Nothing in
     * the validated path can fail that late — that is the point of validating —
     * so the failure is injected at the one place a real one would happen: the
     * insert. Remove DB::transaction from store() and this goes red with a
     * client row nobody can sign in to.
     */
    public function test_a_login_that_fails_to_save_leaves_no_client_behind(): void
    {
        User::creating(fn () => throw new RuntimeException('the login could not be written'));
        $this->withoutExceptionHandling();

        // Nothing is asserted inside the try: PHPUnit's own fail() throws a
        // RuntimeException of its own, so a catch here would swallow it and the
        // test would pass having proved nothing.
        $thrown = null;

        try {
            $this->actingAs($this->admin)
                ->post(route('admin.clients.store'), $this->company() + $this->portal());
        } catch (\Throwable $e) {
            $thrown = $e;
        } finally {
            User::flushEventListeners();
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown, 'The injected failure did not happen, so this proves nothing.');
        $this->assertSame('the login could not be written', $thrown->getMessage());

        $this->assertSame(0, Client::count(), 'The client was committed without its login — a half-made client is exactly what this screen exists to prevent.');
    }

    /**
     * Both halves filled is a conflict, NAMED rather than resolved.
     *
     * Two different accounts were asked for. Preferring either one silently
     * attaches the client to a login the admin did not choose.
     */
    public function test_filling_both_the_dropdown_and_the_new_fields_is_refused(): void
    {
        $existing = User::factory()->create(['email' => 'already@bartleby-ironworks.test']);
        $existing->assignRole(ClientController::PORTAL_ROLE);

        $response = $this->actingAs($this->admin)->post(
            route('admin.clients.store'),
            $this->company(['user_id' => $existing->getKey()]) + $this->portal(),
        );

        $response->assertSessionHasErrors('user_id');

        $message = session('errors')->first('user_id');
        $this->assertStringContainsString('Client login', $message, 'The message has to name the existing-account half.');
        $this->assertStringContainsString('Portal access', $message, 'And the create-a-new-one half.');

        $this->assertSame(0, Client::count());
        $this->assertSame(0, User::where('email', 'zephyrine@bartleby-ironworks.test')->count());
    }

    /** Half-filled is refused too, rather than saving a client and dropping the rest. */
    public function test_a_half_filled_portal_section_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.clients.store'), $this->company() + ['portal_email' => 'zephyrine@bartleby-ironworks.test'])
            ->assertSessionHasErrors(['portal_name', 'portal_password']);

        $this->assertSame(0, Client::count());
    }

    /* -------------------------------- The role ------------------------------ */

    /**
     * The account this screen creates reaches NOTHING in the admin panel.
     *
     * Asserted on the permissions rather than on the role name, because the role
     * name is what the other test pins and a role is only as narrow as its
     * permission list. `client` holds none at all by design — if somebody grants
     * it one, this is what says so.
     */
    public function test_a_login_created_here_holds_no_permission_at_all(): void
    {
        $this->actingAs($this->admin)->post(route('admin.clients.store'), $this->company() + $this->portal());

        $user = User::where('email', 'zephyrine@bartleby-ironworks.test')->sole();

        $this->assertSame([], $user->getAllPermissions()->pluck('name')->all());
        $this->assertSame([ClientController::PORTAL_ROLE], $user->getRoleNames()->all());

        // And the door it is actually kept out of.
        $this->actingAs($user)->get(route('admin.clients.index'))->assertForbidden();
    }

    /* --------------------------------- Edit --------------------------------- */

    /** The section is on New client only, and edit ignores a posted copy of it. */
    public function test_editing_a_client_does_not_take_portal_fields(): void
    {
        $client = Client::create(['name' => 'Bartleby', 'is_active' => true]);
        $before = User::count();

        $this->actingAs($this->admin)
            ->put(route('admin.clients.update', $client), $this->company(['name' => 'Bartleby Renamed']) + $this->portal())
            ->assertRedirect(route('admin.clients.index'));

        $this->assertSame('Bartleby Renamed', $client->fresh()->name);
        $this->assertSame($before, User::count(), 'Edit must not quietly create an account.');

        $this->actingAs($this->admin)->get(route('admin.clients.edit', $client))
            ->assertOk()
            ->assertDontSee('portal_email');
    }

    /** And the create screen does offer it. */
    public function test_the_create_screen_offers_the_portal_fields(): void
    {
        $this->actingAs($this->admin)->get(route('admin.clients.create'))
            ->assertOk()
            ->assertSee('Portal access')
            ->assertSee('portal_email')
            ->assertSee('portal_password_confirmation');
    }
}
