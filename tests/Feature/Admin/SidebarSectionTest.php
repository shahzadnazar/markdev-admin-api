<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * A nav section is never drawn with nothing under it.
 *
 * The heading and the items are gated separately, so the two can drift: the
 * Team section was drawn for anyone holding `projects.view` or `tasks.view`
 * while its only item needed `teams.view`, which gave a team member a heading
 * over empty space. This derives the sections from the rendered sidebar rather
 * than listing them, so a section added in a later phase is covered the day it
 * appears.
 */
class SidebarSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /** Every role in the seeder — including the ones that see no sidebar at all. */
    public static function roles(): array
    {
        return [
            'super-admin' => ['super-admin'],
            'admin' => ['admin'],
            'manager' => ['manager'],
            'instructor' => ['instructor'],
            'team-lead' => ['team-lead'],
            'team' => ['team'],
            'client' => ['client'],
            'student' => ['student'],
        ];
    }

    /** The sidebar as this viewer sees it, rendered on its own. */
    protected function sidebarFor(User $user): string
    {
        $this->actingAs($user);

        $html = Blade::render('<x-admin.sidebar />');

        // Asserted, not assumed: a render that failed or changed shape would
        // otherwise make every section check below pass by finding nothing.
        $this->assertStringContainsString('admin-sidebar', $html, 'The sidebar did not render.');

        return $html;
    }

    /**
     * label => the markup between its <nav> tags.
     *
     * @return array<string, string>
     */
    protected function sections(User $user): array
    {
        $html = $this->sidebarFor($user);

        preg_match_all(
            '/nav-section-label[^>]*>\s*(.*?)\s*<\/p>\s*<nav[^>]*>(.*?)<\/nav>/s',
            $html,
            $matches,
            PREG_SET_ORDER,
        );

        return collect($matches)->mapWithKeys(fn ($match) => [trim($match[1]) => $match[2]])->all();
    }

    /** @dataProvider roles */
    public function test_no_section_is_drawn_empty(string $role): void
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        foreach ($this->sections($user) as $label => $body) {
            $this->assertStringContainsString('<a ', $body, sprintf(
                'The "%s" section is drawn for a %s with no items under it. Its @can/@canany has to '
                .'match the items it actually contains — see resources/views/components/admin/sidebar.blade.php.',
                $label,
                $role,
            ));
        }
    }

    /** The section that was wrong, named, so the fix cannot quietly regress. */
    public function test_a_team_member_sees_no_team_heading_until_they_have_a_team_screen(): void
    {
        $member = User::factory()->create();
        $member->assignRole('team');

        $this->assertTrue($member->can('projects.view'));
        $this->assertFalse($member->can('teams.view'));
        $this->assertArrayNotHasKey('Team', $this->sections($member));
    }

    public function test_a_team_lead_sees_the_team_section_and_nothing_else(): void
    {
        $lead = User::factory()->create();
        $lead->assignRole('team-lead');

        $this->assertSame(['Team'], array_keys($this->sections($lead)));
    }

    public function test_a_client_sees_no_sections_at_all(): void
    {
        $client = User::factory()->create();
        $client->assignRole('client');

        $this->assertSame([], $this->sections($client));
    }
}
