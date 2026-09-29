<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AnnouncementPublished;
use App\Notifications\AttendanceModeChanged;
use App\Notifications\MentionedInComment;
use App\Notifications\ProjectOverdue;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * The bell and the page behind it belong to EVERY panel user.
 *
 * The bug this file exists for: `notifications/read-all` sat inside the academy
 * route group, so the bell the shared layout drew for a team-lead and a team
 * member answered their click with a 403. SidebarSectionTest covers the control
 * per role; this covers the page, and covers the academy's own notifications
 * still arriving on it unchanged.
 */
class NotificationCentreTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------ The 403 bug ----------------------------- */

    public function test_a_team_member_clears_the_bell_without_a_403(): void
    {
        $member = $this->roleUser('team');
        $member->notify(new AttendanceModeChanged('manual'));

        $this->actingAs($member)
            ->from(route('admin.tasks.index'))
            ->post(route('admin.notifications.read-all'))
            ->assertRedirect();

        $this->assertSame(0, $member->unreadNotifications()->count());
    }

    public function test_an_instructor_clears_the_bell_without_a_403(): void
    {
        $instructor = $this->roleUser('instructor');
        $instructor->notify(new AttendanceModeChanged('biometric'));

        $this->actingAs($instructor)
            ->from(route('admin.dashboard'))
            ->post(route('admin.notifications.read-all'))
            ->assertRedirect();

        $this->assertSame(0, $instructor->unreadNotifications()->count());
    }

    /** The union gate admits nobody new: no panel, no notifications page. */
    public function test_a_client_and_a_student_are_refused(): void
    {
        foreach (['client', 'student'] as $role) {
            $user = $this->roleUser($role);

            $this->actingAs($user)->get(route('admin.notifications.index'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.notifications.read-all'))->assertForbidden();
        }
    }

    /* -------------------------------- The page ------------------------------ */

    /**
     * An instructor's existing notifications appear exactly as they do now.
     *
     * AnnouncementPublished carries both a portal path and an `admin_action_url`
     * for the panel; the page reads the same precedence the topbar dropdown
     * does, so the two cannot disagree about where one notification goes.
     */
    public function test_an_academy_notification_renders_on_the_new_page(): void
    {
        $instructor = $this->roleUser('instructor');
        $author = $this->roleUser('super-admin');

        $announcement = Announcement::create([
            'author_id' => $author->id,
            'course_id' => null,
            'title' => 'Fee deadline moved to Friday',
            'body' => 'The window closes at 5pm.',
            'is_pinned' => false,
            'published_at' => now(),
        ]);

        $instructor->notify(new AnnouncementPublished($announcement));
        $instructor->notify(new AttendanceModeChanged('manual'));

        $this->actingAs($instructor)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertSee('Fee deadline moved to Friday')
            ->assertSee('Attendance mode changed')
            ->assertSee('2 unread');
    }

    public function test_the_page_shows_unread_before_read(): void
    {
        $member = $this->roleUser('team');

        $member->notify(new AttendanceModeChanged('manual'));
        $member->notifications()->update(['read_at' => now()]);
        $older = $member->notifications()->firstOrFail();
        // Backdated, so "unread first" is the only thing that could put the
        // newer-but-read one second.
        $older->forceFill(['created_at' => now()->subDays(2)])->save();

        $member->notify(new AttendanceModeChanged('biometric'));

        $html = $this->actingAs($member)->get(route('admin.notifications.index'))->assertOk()->getContent();

        $unread = strpos($html, 'Device punches fill the register');
        $read = strpos($html, 'Please mark your register');

        $this->assertNotFalse($unread);
        $this->assertNotFalse($read);
        $this->assertLessThan($read, $unread, 'the page is not putting unread notifications first');
    }

    /** Opening one marks it read and goes where it pointed. */
    public function test_opening_a_notification_marks_it_read_and_follows_it(): void
    {
        $lead = $this->roleUser('team-lead', ['name' => 'Ayesha Khan']);
        $team = $this->makeTeam('Web', $lead, [$lead]);
        $project = $this->makeProject($team);
        $project->update(['due_date' => Project::dayKey($this->monday()->copy()->subDay())]);

        $lead->notify(new ProjectOverdue($project, 1));
        $row = $lead->notifications()->firstOrFail();

        $this->actingAs($lead)
            ->post(route('admin.notifications.read', $row->id))
            ->assertRedirect(route('admin.projects.show', $project, absolute: false));

        $this->assertNotNull($row->fresh()->read_at);
    }

    /**
     * A portal-only link goes nowhere rather than to a 404.
     *
     * The academy's student-facing classes point at `/payments` and `/attendance`,
     * which do not exist in this panel.
     */
    public function test_a_portal_only_link_sends_you_back_instead_of_to_a_404(): void
    {
        $instructor = $this->roleUser('instructor');
        $instructor->notify(new AttendanceModeChanged('manual'));

        $row = $instructor->notifications()->firstOrFail();
        $row->forceFill(['data' => ['title' => 'Payment approved', 'message' => 'Done', 'action_url' => '/payments']])->save();

        $this->actingAs($instructor)
            ->from(route('admin.notifications.index'))
            ->post(route('admin.notifications.read', $row->id))
            ->assertRedirect(route('admin.notifications.index'));

        $this->assertNotNull($row->fresh()->read_at);
    }

    /**
     * NOBODY READS ANYBODY ELSE'S.
     *
     * The row is looked up through the signed-in user's own relationship, so
     * somebody else's uuid is a 404 and not a refusal — a refusal would confirm
     * the row exists.
     */
    public function test_one_persons_notification_cannot_be_read_by_another(): void
    {
        $mine = $this->roleUser('team', ['name' => 'Bilal Ahmed']);
        $theirs = $this->roleUser('team', ['name' => 'Dania Sheikh']);

        $theirs->notify(new AttendanceModeChanged('manual'));
        $row = $theirs->notifications()->firstOrFail();

        $this->actingAs($mine)->post(route('admin.notifications.read', $row->id))->assertNotFound();
        $this->assertNull($row->fresh()->read_at);

        // And clearing yours does not clear theirs.
        $this->actingAs($mine)->from(route('admin.tasks.index'))->post(route('admin.notifications.read-all'))->assertRedirect();
        $this->assertSame(1, $theirs->unreadNotifications()->count());
    }

    /** The bell's own dropdown offers the page, for a team role too. */
    public function test_the_bell_links_to_the_full_list(): void
    {
        $member = $this->roleUser('team');

        $this->actingAs($member)
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee(route('admin.notifications.index'), escape: false)
            ->assertSee('See all notifications');
    }

    /** Nothing in this phase invented a channel other than the portal bell. */
    public function test_a_mention_notification_is_a_database_row_and_nothing_else(): void
    {
        $lead = $this->roleUser('team-lead', ['name' => 'Ayesha Khan']);
        $member = $this->roleUser('team', ['name' => 'Bilal Ahmed']);
        $team = $this->makeTeam('Web', $lead, [$lead, $member]);
        $project = $this->makeProject($team);

        $this->actingAs($lead)->post(
            route('admin.projects.comments.store', $project),
            ['body' => 'Over to you @bilal.ahmed'],
        )->assertSessionHasNoErrors();

        $row = $member->notifications()->where('type', MentionedInComment::class)->firstOrFail();

        $this->assertSame(['database'], (new MentionedInComment($project->comments()->firstOrFail()))->via($member));

        // And it shows up on the page, with a link into the panel.
        $this->actingAs($member)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertSee('Ayesha Khan mentioned you');

        $this->assertStringStartsWith('/admin/', $row->data['action_url']);
    }

    /** Every panel role can open the page — the union gate, per role. */
    public function test_every_panel_role_opens_the_page(): void
    {
        foreach (['super-admin', 'admin', 'manager', 'instructor', 'team-lead', 'team'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->actingAs($user)->get(route('admin.notifications.index'))->assertOk($role);
        }
    }
}
