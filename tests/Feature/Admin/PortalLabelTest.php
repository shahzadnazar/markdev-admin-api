<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\PortalLabel;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The panel calls itself what the viewer actually is.
 *
 * It said "Admin Portal" to everyone, including instructors. The label follows
 * the role now, decided in one place so a fifth role cannot quietly go on
 * being called Admin.
 */
class PortalLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function userWith(string ...$roles): User
    {
        $user = User::factory()->create();
        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function roleLabels(): array
    {
        return [
            'super admin' => ['super-admin', 'Super Admin Portal'],
            'admin' => ['admin', 'Admin Portal'],
            'manager' => ['manager', 'Manager Portal'],
            'instructor' => ['instructor', 'Instructor Portal'],
        ];
    }

    /* ------------------------------ Each role ------------------------------ */

    /** @dataProvider roleLabels */
    public function test_each_role_resolves_to_its_own_label($role, $label): void
    {
        $this->assertSame($label, $this->userWith($role)->portalLabel());
    }

    /** @dataProvider roleLabels */
    public function test_each_role_sees_its_own_label_in_the_sidebar($role, $label): void
    {
        $this->actingAs($this->userWith($role))
            ->get(route('admin.dashboard'))->assertOk()
            ->assertSee($label, false);
    }

    public function test_an_instructor_is_no_longer_told_they_are_an_admin(): void
    {
        // The reported bug, stated as its own test so it cannot come back
        // through a change that still leaves the other four passing.
        $this->actingAs($this->userWith('instructor'))
            ->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Instructor Portal', false)
            ->assertDontSee('>Admin Portal<', false);
    }

    /* ----------------------------- Two roles ------------------------------- */

    public function test_two_roles_resolve_to_the_more_senior_one(): void
    {
        $this->assertSame('Admin Portal', $this->userWith('instructor', 'admin')->portalLabel());
        $this->assertSame('Super Admin Portal', $this->userWith('manager', 'super-admin')->portalLabel());
        $this->assertSame('Manager Portal', $this->userWith('instructor', 'manager')->portalLabel());
    }

    public function test_the_answer_does_not_depend_on_the_order_they_were_assigned(): void
    {
        // Whichever way round they go in, the map's order decides — not the
        // order the pivot rows happen to come back in.
        $this->assertSame(
            $this->userWith('admin', 'instructor')->portalLabel(),
            $this->userWith('instructor', 'admin')->portalLabel(),
        );
        $this->assertSame('Admin Portal', $this->userWith('admin', 'instructor')->portalLabel());
    }

    public function test_precedence_is_the_order_of_the_map(): void
    {
        // Stated as an assertion so reordering LABELS is a deliberate act with
        // a failing test behind it, rather than a silent change of meaning.
        $this->assertSame(
            ['super-admin', 'admin', 'manager', 'instructor'],
            array_keys(PortalLabel::LABELS),
        );
    }

    /* ---------------------------- The fallbacks ---------------------------- */

    public function test_an_unmapped_role_gets_a_label_of_its_own(): void
    {
        Role::findOrCreate('registrar', 'web');

        $this->assertSame('Registrar Portal', $this->userWith('registrar')->portalLabel());
    }

    public function test_a_hyphenated_unmapped_role_reads_as_words(): void
    {
        Role::findOrCreate('front-desk', 'web');

        $this->assertSame('Front Desk Portal', $this->userWith('front-desk')->portalLabel());
    }

    public function test_two_unmapped_roles_still_give_the_same_answer_every_time(): void
    {
        Role::findOrCreate('registrar', 'web');
        Role::findOrCreate('bursar', 'web');

        $first = $this->userWith('registrar', 'bursar')->portalLabel();
        $second = $this->userWith('bursar', 'registrar')->portalLabel();

        $this->assertSame($first, $second);
        $this->assertSame('Bursar Portal', $first, 'Alphabetical, so it is stable.');
    }

    public function test_a_user_with_no_role_gets_the_bare_label_not_a_blank(): void
    {
        $label = $this->userWith()->portalLabel();

        $this->assertSame('Portal', $label);
        $this->assertNotSame('', $label);
    }

    public function test_nobody_signed_in_resolves_without_erroring(): void
    {
        // The 403 page renders for guests too.
        $this->assertSame('Admin Portal', PortalLabel::for(null));
    }

    /* ------------------------------- The 403 -------------------------------- */

    /**
     * A 403 raised with its own sentence keeps it.
     *
     * Only the generic fallback follows the role. The specific messages say
     * what actually happened — which is more use to the reader than the
     * panel's name — and this is what stops the rename reaching them.
     */
    public function test_a_403_with_its_own_message_is_not_overwritten(): void
    {
        $this->actingAs($this->userWith('instructor'));

        $html = view('errors.403', [
            'exception' => new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                'Absent is final. Ask an admin to correct it.',
            ),
        ])->render();

        $this->assertStringContainsString('Absent is final. Ask an admin to correct it.', $html);
        $this->assertStringNotContainsString("doesn't have permission to view this area", $html);
    }

    public function test_a_real_403_still_renders_that_page(): void
    {
        $this->actingAs($this->userWith('instructor'))
            ->get('/admin/settings')
            ->assertForbidden()
            ->assertSee('Access restricted', false);
    }

    public function test_the_generic_403_names_the_viewer_s_own_portal(): void
    {
        $html = view('errors.403', ['exception' => null])->render();
        $this->assertStringContainsString('admin portal', $html);

        $this->actingAs($this->userWith('instructor'));
        $html = view('errors.403', ['exception' => null])->render();
        $this->assertStringContainsString('instructor portal', $html);
        $this->assertStringNotContainsString('admin portal', $html);
    }

    /* -------------------------- Layout still intact ------------------------- */

    public function test_the_sidebar_still_renders_its_brand_and_collapse_control(): void
    {
        $response = $this->actingAs($this->userWith('instructor'))
            ->get(route('admin.dashboard'))->assertOk();

        // The label sits inside the block the collapsed rules hide, so the
        // class that does the hiding has to still be around it.
        $response->assertSee('sidebar-brand-text', false);
        $response->assertSee('collapsed = ! collapsed', false);
        // And the footer link from 9b590e6 is untouched.
        $response->assertSee('aria-label="Your profile"', false);
    }

    public function test_the_login_page_keeps_the_generic_label(): void
    {
        // Nobody is signed in there, so there is no role to follow.
        $this->get(route('login'))->assertOk()->assertSee('Admin Portal', false);
    }

    public function test_the_label_gates_nothing(): void
    {
        // An instructor's screens are decided by permissions, not by what the
        // sidebar calls itself. Same access before and after the rename.
        $instructor = $this->userWith('instructor');

        $this->actingAs($instructor)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($instructor)->get('/admin/settings')->assertForbidden();
    }
}
