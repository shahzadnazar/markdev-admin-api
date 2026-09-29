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

    /**
     * label => the item labels under it.
     *
     * @return array<string, array<int, string>>
     */
    protected function items(User $user): array
    {
        return collect($this->sections($user))
            ->map(function (string $body) {
                preg_match_all('/<span class="nav-label truncate">\s*(.*?)\s*<\/span>/s', $body, $labels);

                return array_map(
                    fn (string $label) => trim(html_entity_decode(strip_tags($label))),
                    $labels[1],
                );
            })
            ->all();
    }

    /**
     * Exactly what each seeded role is offered.
     *
     * Written down because the Learning section's gate was rewritten to list
     * the permissions its items actually use, and "nobody's nav changed" is a
     * claim that needs checking rather than asserting. Only one thing did
     * change, and deliberately: a manager no longer sees Notes, because they
     * hold no notes permission and the screen answered them with a 403.
     *
     * @dataProvider navigation
     */
    public function test_each_role_is_offered_exactly_these_items(string $role, array $expected): void
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertSame($expected, $this->items($user));
    }

    /** @return array<string, array{string, array<string, array<int, string>>}> */
    public static function navigation(): array
    {
        $learning = ['Categories', 'Course Content', 'Notes', 'Enrollments', 'Assignments', 'Quizzes', 'Attendance', 'Leave Requests', 'Biometric', 'Certificates'];

        return [
            'super-admin' => ['super-admin', [
                'Overview' => ['Dashboard'],
                'People' => ['Students', 'Instructors', 'Staff & Users', 'Roles & Permissions'],
                'Learning' => $learning,
                'Team' => ['Teams', 'Projects', 'Clients'],
                'Engagement' => ['Announcements', 'Help Center'],
                'Finance' => ['Billing', 'Payment Methods'],
                'System' => ['Private notes', 'Audit Logs', 'Reports', 'Settings', 'Attendance Slots', 'Task Statuses', 'Project Statuses'],
            ]],
            'admin' => ['admin', [
                'Overview' => ['Dashboard'],
                'People' => ['Students', 'Instructors', 'Staff & Users'],
                'Learning' => $learning,
                'Team' => ['Teams', 'Projects', 'Clients'],
                'Engagement' => ['Announcements', 'Help Center'],
                'Finance' => ['Billing', 'Payment Methods'],
                'System' => ['Audit Logs', 'Reports', 'Settings', 'Attendance Slots', 'Task Statuses', 'Project Statuses'],
            ]],
            // No Notes: a manager holds no notes permission, and the screen
            // behind it has always refused them.
            'manager' => ['manager', [
                'Overview' => ['Dashboard'],
                'People' => ['Students', 'Instructors', 'Staff & Users'],
                'Learning' => ['Categories', 'Course Content', 'Enrollments', 'Assignments', 'Quizzes', 'Attendance', 'Leave Requests', 'Biometric'],
                'Engagement' => ['Announcements'],
                'System' => ['Reports'],
            ]],
            'instructor' => ['instructor', [
                'Overview' => ['Dashboard'],
                'Learning' => ['Categories', 'Course Content', 'Notes', 'Enrollments', 'Assignments', 'Quizzes', 'Attendance', 'Leave Requests'],
                'Engagement' => ['Announcements'],
            ]],
            // A lead runs a team and sees its work; no Clients — they never
            // learn who a project is for.
            'team-lead' => ['team-lead', ['Team' => ['Teams', 'Projects']]],
            // A member has no team list of their own yet, only the work.
            'team' => ['team', ['Team' => ['Projects']]],
            'client' => ['client', []],
            'student' => ['student', []],
        ];
    }

    /**
     * Nothing in the sidebar opens a screen the viewer would be refused.
     *
     * The Notes item was ungated while its route carried can:notes.view, so a
     * manager was shown a door that answered 403 — the "empty room they were
     * invited into" the trashed-filter concern is about. Every item is followed
     * here, for every role, so the next one cannot go unnoticed.
     *
     * @dataProvider roles
     */
    public function test_every_item_a_role_is_offered_actually_opens(string $role): void
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        preg_match_all('/<a href="([^"]+)"[^>]*>.*?<span class="nav-label truncate">\s*(.*?)\s*<\/span>/s', $this->sidebarFor($user), $links, PREG_SET_ORDER);

        foreach ($links as [$all, $href, $label]) {
            $this->actingAs($user)->get(html_entity_decode($href))->assertOk(sprintf(
                'The sidebar offers a %s the "%s" item, and it answers with a refusal. An item has to '
                .'be gated on the same permission as the screen behind it.',
                $role,
                trim(html_entity_decode(strip_tags($label))),
            ));
        }
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
    public function test_a_team_member_sees_only_the_items_they_can_open(): void
    {
        $member = User::factory()->create();
        $member->assignRole('team');

        $this->assertTrue($member->can('projects.view'));
        $this->assertFalse($member->can('teams.view'));

        // The heading is theirs now, because phase 2 gave it an item they can
        // open. What must never appear under it is Teams or Clients.
        $this->assertSame(['Projects'], $this->items($member)['Team']);
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
