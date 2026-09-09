<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The name and role at the foot of the sidebar open your own profile.
 *
 * It read as clickable and was not. It is a link now, ungated: everyone has an
 * account to manage, so this is the one thing in the sidebar that is not
 * behind a permission.
 *
 * The route carries no id — ProfileController reads $request->user() — so
 * "someone else's profile" is not a thing the link can express. The test for
 * that asserts the shape of the route rather than trying to craft a URL that
 * does not exist.
 */
class SidebarProfileLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function userWith(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, array{0: string}> */
    public static function panelRoles(): array
    {
        return [
            'admin' => ['admin'],
            'super admin' => ['super-admin'],
            'instructor' => ['instructor'],
            'manager' => ['manager'],
        ];
    }

    /* ----------------------------- The link itself ---------------------------- */

    /** @dataProvider panelRoles */
    public function test_the_sidebar_footer_links_to_the_profile($role): void
    {
        $user = $this->userWith($role);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('href="'.route('profile.edit').'"', false)
            ->assertSee('aria-label="Your profile"', false)
            ->assertSee($user->name, false);
    }

    /** @dataProvider panelRoles */
    public function test_every_role_lands_on_their_own_profile($role): void
    {
        $user = $this->userWith($role);

        $this->actingAs($user)->get(route('profile.edit'))->assertOk()
            // The page is filled from the signed-in user, so their own details
            // are what it shows.
            ->assertSee($user->email, false);
    }

    public function test_the_footer_is_a_real_link_so_it_is_keyboard_reachable(): void
    {
        // An <a href> is in the tab order and announces itself; the div it
        // replaced was neither. Asserted on the markup because that is what
        // decides it — a div with a click handler would pass a "clicking
        // works" test and still be unreachable by keyboard.
        $html = $this->actingAs($this->userWith('admin'))
            ->get(route('admin.dashboard'))->getContent();

        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="'.preg_quote(route('profile.edit'), '/').'"[^>]*aria-label="Your profile"/s',
            $html,
        );
    }

    public function test_the_footer_is_marked_current_while_the_profile_is_open(): void
    {
        $user = $this->userWith('admin');

        $this->actingAs($user)->get(route('profile.edit'))->assertOk()
            ->assertSee('aria-current="page"', false);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk()
            ->assertDontSee('aria-current="page"', false);
    }

    /**
     * The collapsed sidebar hides the name and role but keeps the link.
     *
     * Collapsing is a CSS class on the shell, so the markup is the same either
     * way — what matters is that the piece which disappears is the text, and
     * the anchor around it is not inside it.
     */
    public function test_the_link_survives_the_collapsed_sidebar(): void
    {
        $html = $this->actingAs($this->userWith('instructor'))
            ->get(route('admin.dashboard'))->getContent();

        // The anchor carries the footer class the collapsed rules target, and
        // the text that gets hidden sits inside it rather than around it.
        $this->assertMatchesRegularExpression(
            '/<a[^>]*class="[^"]*\bsidebar-footer\b[^"]*"[^>]*>.*?sidebar-footer-meta.*?<\/a>/s',
            $html,
        );

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.sidebar-collapsed .sidebar-footer-meta', $css);
        // The old rule centred `.sidebar-footer > div`. The footer is the row
        // itself now, so a rule still reaching for a child would silently stop
        // applying and leave the avatar off centre.
        $this->assertStringNotContainsString('.sidebar-collapsed .sidebar-footer > div', $css);
    }

    /* ------------------------- Nobody else's profile ------------------------- */

    public function test_the_profile_route_takes_no_user_and_cannot_name_another(): void
    {
        $mine = $this->userWith('instructor');
        $other = $this->userWith('admin');

        $this->assertSame([], \Illuminate\Support\Facades\Route::getRoutes()
            ->getByName('profile.edit')->parameterNames());

        // Even asked about someone else, the page answers with you.
        $this->actingAs($mine)->get(route('profile.edit').'?user='.$other->id)->assertOk()
            ->assertSee($mine->email, false)
            ->assertDontSee($other->email, false);
    }

    public function test_a_guest_is_sent_to_login_rather_than_a_profile(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
    }

    /* ------------------------ The page it now leads to ----------------------- */

    /**
     * Breeze ships a "Delete Account" card. UserController::destroy already
     * refuses self-deletion — "You cannot delete your own account from here."
     * — so offering it one click from every page would be that same deletion
     * by a quieter door.
     */
    public function test_the_profile_page_does_not_offer_self_deletion(): void
    {
        $this->actingAs($this->userWith('super-admin'))
            ->get(route('profile.edit'))->assertOk()
            ->assertSee('Update Password', false)
            ->assertDontSee('Delete Account', false);
    }

    public function test_the_admin_users_screen_still_refuses_self_deletion(): void
    {
        $admin = $this->userWith('super-admin');

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $admin))
            ->assertForbidden();

        $this->assertNotNull($admin->fresh());
    }
}
