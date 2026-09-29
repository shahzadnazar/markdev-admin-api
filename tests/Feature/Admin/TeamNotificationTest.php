<?php

namespace Tests\Feature\Admin;

use App\Models\ClientQuestion;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\Team;
use App\Models\TeamAbsenceFine;
use App\Models\TeamAttendance;
use App\Models\TeamLeaveApplication;
use App\Models\User;
use App\Notifications\ClientAskedAQuestion;
use App\Notifications\MentionedInComment;
use App\Notifications\MilestoneDueTomorrow;
use App\Notifications\ProjectOverdue;
use App\Notifications\TaskAssigned;
use App\Notifications\TaskReassignedAway;
use App\Notifications\TeamAbsenceFineCharged;
use App\Notifications\TeamLeaveReviewed;
use App\Services\TaskWorkflow;
use App\Support\PortalNotifier;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * SEVEN events, and nobody is ever told about work they cannot see.
 *
 * One test per event, each asserting the count as well as the recipient: "one
 * notification, to exactly the right person" is two claims and a test that only
 * checked the recipient would miss a duplicate.
 */
class TeamNotificationTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected User $admin;

    protected User $lead;

    protected User $member;

    protected User $other;

    protected User $outsideLead;

    protected Team $team;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();

        $this->admin = $this->roleUser('super-admin', ['name' => 'Root Admin']);
        $this->lead = $this->roleUser('team-lead', ['name' => 'Ayesha Khan']);
        $this->member = $this->roleUser('team', ['name' => 'Bilal Ahmed']);
        $this->other = $this->roleUser('team', ['name' => 'Dania Sheikh']);
        $this->outsideLead = $this->roleUser('team-lead', ['name' => 'Chandni Rao']);

        $this->team = $this->makeTeam('Web', $this->lead, [$this->lead, $this->member, $this->other]);
        $this->makeTeam('Graphics', $this->outsideLead, [$this->outsideLead]);

        $this->project = $this->makeProject($this->team);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** How many of one class this person has been sent. */
    protected function countFor(User $user, string $class): int
    {
        return $user->notifications()->where('type', $class)->count();
    }

    protected function dataFor(User $user, string $class): array
    {
        return (array) $user->notifications()->where('type', $class)->firstOrFail()->data;
    }

    /* ------------------------ 1. you were mentioned ------------------------- */

    public function test_a_mention_notifies_exactly_the_person_mentioned(): void
    {
        $this->actingAs($this->lead)->post(
            route('admin.projects.comments.store', $this->project),
            ['body' => 'Can you take the checkout, @bilal.ahmed?'],
        )->assertSessionHasNoErrors();

        $this->assertSame(1, $this->countFor($this->member, MentionedInComment::class));

        // Not the author, and not the teammate who was not named.
        $this->assertSame(0, $this->countFor($this->lead, MentionedInComment::class));
        $this->assertSame(0, $this->countFor($this->other, MentionedInComment::class));

        $data = $this->dataFor($this->member, MentionedInComment::class);
        $this->assertSame('Ayesha Khan mentioned you', $data['title']);
        $this->assertStringContainsString('discussion', $data['message']);
        // A panel path, not an absolute URL: the topbar decides whether a link
        // belongs to this panel by looking for /admin at the front of it.
        $this->assertStringStartsWith('/admin/projects/', $data['action_url']);
    }

    /**
     * Editing a comment does not ring the same bell twice.
     *
     * The mention ROWS are the record of who has been told, which is the reason
     * phase 5 wrote them rather than leaving the handles in the text.
     */
    public function test_editing_a_comment_does_not_re_notify_a_mention_that_was_already_there(): void
    {
        $this->actingAs($this->lead)->post(
            route('admin.projects.comments.store', $this->project),
            ['body' => 'Morning @bilal.ahmed'],
        )->assertSessionHasNoErrors();

        $comment = $this->project->comments()->firstOrFail();

        $this->actingAs($this->lead)->put(
            route('admin.projects.comments.update', [$this->project, $comment]),
            ['body' => 'Morning @bilal.ahmed — and welcome @dania.sheikh'],
        )->assertSessionHasNoErrors();

        $this->assertSame(1, $this->countFor($this->member, MentionedInComment::class));
        $this->assertSame(1, $this->countFor($this->other, MentionedInComment::class));
    }

    /**
     * THE LEAK THIS FEATURE COULD HAVE BEEN.
     *
     * A lead on another team cannot open this project, so they are told nothing
     * about it — the title alone would say a project exists, what it is called,
     * and that a colleague is on it, which is exactly what phase 2's scope and
     * gate keep from them.
     *
     * They are not in the mentionable set either, so this asserts BOTH halves:
     * no mention row, and no notification.
     */
    public function test_a_mention_of_somebody_who_cannot_see_the_project_notifies_nobody(): void
    {
        $this->actingAs($this->lead)->post(
            route('admin.projects.comments.store', $this->project),
            ['body' => 'Any thoughts @chandni.rao?'],
        )->assertSessionHasNoErrors();

        $this->assertSame(0, $this->countFor($this->outsideLead, MentionedInComment::class));
        $this->assertSame(0, $this->project->comments()->firstOrFail()->mentions()->count());
    }

    /**
     * And the case where the mentionable set is NOT enough on its own.
     *
     * A task comment's mentionable set is the task's whole TEAM; a member sees a
     * task only while they hold or held a stint on it. So a teammate who was
     * never on this task is mentionable and must not be told it exists — the one
     * place the visibility check does work the mention set cannot do for it.
     */
    public function test_a_teammate_with_no_stint_on_the_task_is_mentionable_and_not_notified(): void
    {
        $task = $this->makeTask($this->team, ['project_id' => $this->project->id]);
        $this->makeStint($task, $this->member, 3, $this->monday());

        $this->actingAs($this->lead)->post(
            route('admin.tasks.comments.store', $task),
            ['body' => 'Sanity check please @dania.sheikh and @bilal.ahmed'],
        )->assertSessionHasNoErrors();

        // Both are mentionable, so both rows are written…
        $this->assertSame(2, $task->comments()->firstOrFail()->mentions()->count());

        // …and only the one who can actually see the task is told.
        $this->assertSame(1, $this->countFor($this->member, MentionedInComment::class));
        $this->assertSame(0, $this->countFor($this->other, MentionedInComment::class));
    }

    public function test_a_channel_mention_crosses_teams_because_the_channel_does(): void
    {
        $this->actingAs($this->lead)->post(route('admin.team-channel.store'), [
            'body' => 'Anyone free to look at a logo, @chandni.rao?',
        ])->assertSessionHasNoErrors();

        // The one surface with no work behind it, so there is nothing to narrow
        // it: asking somebody on Graphics a question is what it is for.
        $this->assertSame(1, $this->countFor($this->outsideLead, MentionedInComment::class));
    }

    /* --------------------- 2 and 3. a task changes hands -------------------- */

    public function test_an_assignment_notifies_the_person_it_went_to(): void
    {
        $task = $this->makeTask($this->team, ['project_id' => $this->project->id]);

        $this->actingAs($this->lead)
            ->post(route('admin.tasks.assign.store', $task), ['user_id' => $this->member->id, 'days_allowed' => 4])
            ->assertRedirect();

        $this->assertSame(1, $this->countFor($this->member, TaskAssigned::class));
        // Nobody else, and not the lead who did it.
        $this->assertSame(0, $this->countFor($this->lead, TaskAssigned::class));
        $this->assertSame(0, $this->countFor($this->other, TaskAssigned::class));

        $data = $this->dataFor($this->member, TaskAssigned::class);
        $this->assertStringContainsString('4 day(s)', $data['message']);
        // The project by NAME and CODE. Never the client, never the value.
        $this->assertStringContainsString($this->project->code, $data['message']);
        $this->assertStringNotContainsString('Bartleby', $data['message']);
        $this->assertStringNotContainsString('987654', $data['message']);
    }

    /**
     * A reassignment tells the person it LEFT, not only the person it arrived at.
     *
     * Nothing on the leaver's own screens changes except that the work quietly
     * stops being there, so they are the one most likely to miss it.
     */
    public function test_a_reassignment_notifies_the_person_it_left(): void
    {
        $task = $this->makeTask($this->team, ['project_id' => $this->project->id]);

        $this->actingAs($this->lead)
            ->post(route('admin.tasks.assign.store', $task), ['user_id' => $this->member->id, 'days_allowed' => 4])
            ->assertRedirect();

        $this->actingAs($this->lead)
            ->post(route('admin.tasks.assign.store', $task), ['user_id' => $this->other->id, 'days_allowed' => 3])
            ->assertRedirect();

        $this->assertSame(1, $this->countFor($this->member, TaskReassignedAway::class));
        $this->assertSame(1, $this->countFor($this->other, TaskAssigned::class));

        // The leaver is not told they were assigned it again, and the arriver is
        // not told it was taken off them.
        $this->assertSame(1, $this->countFor($this->member, TaskAssigned::class));
        $this->assertSame(0, $this->countFor($this->other, TaskReassignedAway::class));

        $data = $this->dataFor($this->member, TaskReassignedAway::class);
        $this->assertStringContainsString('Dania Sheikh', $data['message']);
        // Said plainly, because somebody who thinks they were marked late for a
        // handover will argue about a number that was never counted.
        $this->assertStringContainsString('handed over', $data['message']);
    }

    public function test_a_lead_assigning_themselves_is_not_notified_of_their_own_click(): void
    {
        $task = $this->makeTask($this->team, ['project_id' => $this->project->id]);

        $this->actingAs($this->lead)
            ->post(route('admin.tasks.assign.store', $task), ['user_id' => $this->lead->id, 'days_allowed' => 2])
            ->assertRedirect();

        $this->assertSame(0, $this->countFor($this->lead, TaskAssigned::class));
    }

    /* ----------------------------- 4. your leave ---------------------------- */

    public function test_a_leave_decision_notifies_the_member_who_applied(): void
    {
        $this->actingAs($this->member)->post(route('admin.team-leave.store'), [
            'from_date' => $this->monday()->toDateString(),
            'to_date' => $this->monday()->copy()->addDay()->toDateString(),
            'reason' => 'Family matter',
        ])->assertSessionHasNoErrors();

        $leave = TeamLeaveApplication::firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.team-leave.review', $leave), [
            'days' => [$this->monday()->toDateString()],
            'review_note' => 'Tuesday is the client demo.',
        ])->assertRedirect();

        $this->assertSame(1, $this->countFor($this->member, TeamLeaveReviewed::class));
        $this->assertSame(0, $this->countFor($this->lead, TeamLeaveReviewed::class));

        $data = $this->dataFor($this->member, TeamLeaveReviewed::class);
        $this->assertSame('Leave partly approved', $data['title']);
        // The counts come off the per-day rows the review just wrote, so a stale
        // relation would say nothing was approved.
        $this->assertStringContainsString('1 day(s) were approved', $data['message']);
        $this->assertStringContainsString('Tuesday is the client demo.', $data['message']);
    }

    /* ------------------------------ 5. a fine ------------------------------- */

    public function test_an_absence_fine_notifies_the_person_charged(): void
    {
        $month = $this->monday()->copy()->startOfMonth();

        // Three absences against an allowance of two: one chargeable.
        foreach ([0, 1, 2] as $offset) {
            TeamAttendance::create([
                'user_id' => $this->member->id,
                'date' => TeamAttendance::dayKey($month->copy()->addDays($offset)),
                'status' => 'absent',
            ]);
        }

        $this->artisan('attendance:charge-absent-fines', ['--month' => $month->toDateString()])
            ->assertSuccessful();

        $this->assertSame(1, $this->countFor($this->member, TeamAbsenceFineCharged::class));
        $this->assertStringContainsString(
            'absence',
            $this->dataFor($this->member, TeamAbsenceFineCharged::class)['message'],
        );

        // A second run finds the ledger row and charges nothing, so it rings
        // nothing. The ROW is the idempotency here — no subject needed.
        $this->artisan('attendance:charge-absent-fines', ['--month' => $month->toDateString()])
            ->assertSuccessful();

        $this->assertSame(1, $this->countFor($this->member, TeamAbsenceFineCharged::class));
    }

    public function test_a_month_that_came_to_nothing_rings_nobody(): void
    {
        $month = $this->monday()->copy()->startOfMonth();

        $this->artisan('attendance:charge-absent-fines', ['--month' => $month->toDateString()])
            ->assertSuccessful();

        // The row exists — "settled at zero" and "never looked at" have to be
        // different — and it is not a charge, so nothing was said.
        $this->assertSame(1, TeamAbsenceFine::where('user_id', $this->member->id)->count());
        $this->assertSame(0, $this->countFor($this->member, TeamAbsenceFineCharged::class));
    }

    /* --------------------- 6 and 7. the daily deadlines --------------------- */

    public function test_a_milestone_due_tomorrow_notifies_the_projects_team(): void
    {
        $milestone = ProjectMilestone::create([
            'project_id' => $this->project->id,
            'name' => 'Design sign-off',
            'due_date' => ProjectMilestone::dayKey($this->monday()->copy()->addDay()),
            'sort_order' => 1,
        ]);

        $this->artisan('team:notify-deadlines')->assertSuccessful();

        // Every member of the team on it, and nobody outside.
        foreach ([$this->lead, $this->member, $this->other] as $recipient) {
            $this->assertSame(1, $this->countFor($recipient, MilestoneDueTomorrow::class), $recipient->name);
        }

        $this->assertSame(0, $this->countFor($this->outsideLead, MilestoneDueTomorrow::class));
        $this->assertSame(0, $this->countFor($this->admin, MilestoneDueTomorrow::class));

        $data = $this->dataFor($this->lead, MilestoneDueTomorrow::class);
        $this->assertStringContainsString('Design sign-off', $data['message']);
        $this->assertStringContainsString($this->project->code, $data['message']);
        // Carried in the STORED row, because that row is what the next run reads.
        $this->assertSame('milestone:'.$milestone->id.':due:'.$milestone->due_date->toDateString(), $data['subject']);
    }

    public function test_a_completed_milestone_due_tomorrow_says_nothing(): void
    {
        ProjectMilestone::create([
            'project_id' => $this->project->id,
            'name' => 'Design sign-off',
            'due_date' => ProjectMilestone::dayKey($this->monday()->copy()->addDay()),
            'completed_on' => ProjectMilestone::dayKey($this->monday()),
            'sort_order' => 1,
        ]);

        $this->artisan('team:notify-deadlines')->assertSuccessful();

        $this->assertSame(0, $this->countFor($this->lead, MilestoneDueTomorrow::class));
    }

    public function test_an_overdue_project_notifies_its_team_once(): void
    {
        $this->project->update(['due_date' => Project::dayKey($this->monday()->copy()->subDays(3))]);

        $this->artisan('team:notify-deadlines')->assertSuccessful();

        $this->assertSame(1, $this->countFor($this->lead, ProjectOverdue::class));
        $this->assertSame(0, $this->countFor($this->outsideLead, ProjectOverdue::class));

        $data = $this->dataFor($this->lead, ProjectOverdue::class);
        $this->assertStringContainsString('3 day(s) ago', $data['message']);
        $this->assertStringNotContainsString('987654', $data['message']);
    }

    /**
     * A paused project is not late.
     *
     * The same rule daysRemaining() returns null for: a project told to stop is
     * not consuming its schedule, so it cannot be overdue against it.
     */
    public function test_a_paused_project_past_its_date_is_not_overdue(): void
    {
        $this->project->update([
            'due_date' => Project::dayKey($this->monday()->copy()->subDays(3)),
            'project_status_id' => $this->projectStatus('paused')->id,
        ]);

        $this->artisan('team:notify-deadlines')->assertSuccessful();

        $this->assertSame(0, $this->countFor($this->lead, ProjectOverdue::class));
    }

    /**
     * THE IDEMPOTENCY. A second run the same day says nothing.
     *
     * Decided on the stored subject, not on a timestamp window — so a re-run
     * after a failure is safe, and a deadline that MOVES and comes round again
     * is a new fact that does get a second notice.
     */
    public function test_the_daily_command_run_twice_in_one_day_sends_nothing_the_second_time(): void
    {
        $milestone = ProjectMilestone::create([
            'project_id' => $this->project->id,
            'name' => 'Design sign-off',
            'due_date' => ProjectMilestone::dayKey($this->monday()->copy()->addDay()),
            'sort_order' => 1,
        ]);

        $this->project->update(['due_date' => Project::dayKey($this->monday()->copy()->subDay())]);

        $this->artisan('team:notify-deadlines')->assertSuccessful();
        $this->artisan('team:notify-deadlines')->assertSuccessful();
        $this->artisan('team:notify-deadlines')->assertSuccessful();

        $this->assertSame(1, $this->countFor($this->lead, MilestoneDueTomorrow::class));
        $this->assertSame(1, $this->countFor($this->lead, ProjectOverdue::class));

        // Moved, and now due tomorrow again: a different fact, so it speaks.
        $milestone->update(['due_date' => ProjectMilestone::dayKey($this->monday()->copy()->addDays(2))]);
        Carbon::setTestNow($this->monday()->copy()->addDay());

        $this->artisan('team:notify-deadlines')->assertSuccessful();

        $this->assertSame(2, $this->countFor($this->lead, MilestoneDueTomorrow::class));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        ProjectMilestone::create([
            'project_id' => $this->project->id,
            'name' => 'Design sign-off',
            'due_date' => ProjectMilestone::dayKey($this->monday()->copy()->addDay()),
            'sort_order' => 1,
        ]);

        $this->artisan('team:notify-deadlines', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, $this->countFor($this->lead, MilestoneDueTomorrow::class));
    }

    /* ---------------------------- The whole rule ---------------------------- */

    /**
     * Clients and academy roles get nothing, in one place rather than eight.
     *
     * PortalNotifier asks whether the recipient is in the team portal at all
     * before it asks anything else, so this is one answer for all eight events —
     * including the one a client causes themselves.
     */
    public function test_a_client_and_an_instructor_are_told_nothing(): void
    {
        $client = $this->roleUser('client');
        $instructor = $this->roleUser('instructor');
        $task = $this->makeTask($this->team, ['project_id' => $this->project->id]);

        foreach ([$client, $instructor] as $outsider) {
            $this->assertFalse(PortalNotifier::mayBeTold($outsider, $task));
            $this->assertFalse(PortalNotifier::mayBeTold($outsider, null));
            $this->assertFalse(PortalNotifier::notify(
                $outsider,
                $task,
                new TaskAssigned($task, 3, $this->lead),
            ));
            $this->assertSame(0, $outsider->notifications()->count());
        }
    }

    /**
     * EIGHT, and eight is a decision.
     *
     * Phase 6 fixed this at seven so an eighth could not arrive unnoticed, and
     * phase 7 moved it to eight on purpose: a client asked a question on a
     * project, sent to that project's team-lead. It passes the same bar the
     * others pass — something a person would otherwise miss, that nothing else
     * on their screens would tell them.
     *
     * THE NUMBER IS NOT A CEILING THAT GETS BUMPED WHENEVER IT IS INCONVENIENT.
     * Failing this test is the prompt to argue for the event, in a commit
     * message, against the standard above — not to add one to the count and
     * move on. A list people scroll past is worse than no list, and it gets
     * that way one defensible addition at a time.
     */
    public function test_there_are_exactly_eight_team_portal_notification_classes(): void
    {
        $classes = collect(glob(app_path('Notifications/*.php')))
            ->map(fn (string $file) => basename($file, '.php'))
            ->values()
            ->all();

        // The academy's six, untouched, plus the team portal's eight.
        $this->assertSame([
            'AnnouncementPublished',
            'AttendanceModeChanged',
            'ClientAskedAQuestion',
            'FeeSubmissionReceived',
            'FeeSubmissionReviewed',
            'InstallmentStatusChanged',
            'LeaveApplicationReviewed',
            'MentionedInComment',
            'MilestoneDueTomorrow',
            'ProjectOverdue',
            'TaskAssigned',
            'TaskReassignedAway',
            'TeamAbsenceFineCharged',
            'TeamLeaveReviewed',
        ], $classes, 'A notification class was added or removed. Eight team events, and the number is a '
            .'decision: argue for a ninth in the commit message, do not raise the count to make this pass.');
    }

    /** Nothing in this phase is delivered anywhere but the portal. */
    public function test_every_notification_is_database_only(): void
    {
        $task = $this->makeTask($this->team, ['project_id' => $this->project->id]);

        $notifications = [
            new TaskAssigned($task, 3, $this->lead),
            new TaskReassignedAway($task, $this->other, $this->lead),
            new MilestoneDueTomorrow(new ProjectMilestone),
            new ProjectOverdue($this->project, 1),
            new TeamLeaveReviewed(new TeamLeaveApplication),
            new TeamAbsenceFineCharged(new TeamAbsenceFine),
            new ClientAskedAQuestion(new ClientQuestion),
        ];

        foreach ($notifications as $notification) {
            $this->assertSame(['database'], $notification->via($this->member), $notification::class);
        }
    }

    /** A reopen puts open work back in somebody's hands — still event 2. */
    public function test_reopening_somebody_elses_task_tells_them_it_is_theirs_again(): void
    {
        $task = $this->makeTask($this->team, ['project_id' => $this->project->id]);
        $workflow = app(TaskWorkflow::class);

        $workflow->assign($task, $this->member, 3, $this->lead);
        $workflow->changeStatus($task->fresh(), $this->taskStatus('done'), $this->member);

        $before = $this->countFor($this->member, TaskAssigned::class);

        $workflow->changeStatus($task->fresh(), $this->taskStatus('active'), $this->lead);

        $this->assertSame($before + 1, $this->countFor($this->member, TaskAssigned::class));
    }

    public function test_reopening_your_own_task_is_silent(): void
    {
        $task = $this->makeTask($this->team, ['project_id' => $this->project->id]);
        $workflow = app(TaskWorkflow::class);

        $workflow->assign($task, $this->member, 3, $this->lead);
        $workflow->changeStatus($task->fresh(), $this->taskStatus('done'), $this->member);

        $before = $this->countFor($this->member, TaskAssigned::class);

        $workflow->changeStatus($task->fresh(), $this->taskStatus('active'), $this->member);

        $this->assertSame($before, $this->countFor($this->member, TaskAssigned::class));
    }
}
