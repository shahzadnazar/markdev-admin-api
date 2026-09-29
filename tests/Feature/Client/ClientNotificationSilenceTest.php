<?php

namespace Tests\Feature\Client;

use App\Models\Client;
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
use App\Support\PortalNotifier;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * A CLIENT IS TOLD NOTHING — by any of the eight events, on their own project.
 *
 * Not "a client has no bell in the layout": that is a fact about a template, and
 * a template is one edit away from growing one. This is a fact about the rows —
 * after every event this system can produce, including the two that are ABOUT a
 * client's own project and the one the client caused themselves, the
 * notifications table holds nothing for them.
 *
 * The team's own notices are asserted alongside, so this cannot pass by nothing
 * having fired at all.
 */
class ClientNotificationSilenceTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected User $admin;

    protected User $lead;

    protected User $member;

    protected User $clientUser;

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
        $this->team = $this->makeTeam('Web', $this->lead, [$this->lead, $this->member]);

        $this->clientUser = $this->roleUser('client', ['name' => 'Zephyrine Quartermain']);
        $client = Client::create([
            'name' => 'Zephyrine Quartermain',
            'company' => 'Bartleby Ironworks PLC',
            'user_id' => $this->clientUser->id,
            'is_active' => true,
        ]);

        $this->project = $this->makeProject($this->team, $client);
    }

    /**
     * The notification classes this person was sent, sorted and de-duplicated.
     *
     * @return array<int, string>
     */
    protected function sorted(User $user): array
    {
        $types = $user->notifications()->pluck('type')->unique()->sort()->values()->all();

        return $types;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Every one of the eight, offered directly to a client and refused.
     *
     * PortalNotifier is the one door, and `inTeamPortal` is the one condition
     * that answers this for all eight — so this is the unit-level statement of
     * the rule, and the flows below are the same rule met in the wild.
     */
    public function test_portal_notifier_refuses_a_client_for_all_eight_events(): void
    {
        $task = $this->makeTask($this->team, ['project_id' => $this->project->id]);
        $question = ClientQuestion::create([
            'project_id' => $this->project->id,
            'asked_by' => $this->clientUser->id,
            'body' => 'When is it live?',
        ]);
        $milestone = ProjectMilestone::create([
            'project_id' => $this->project->id,
            'name' => 'Sign-off',
            'due_date' => ProjectMilestone::dayKey($this->monday()->copy()->addDay()),
            'sort_order' => 1,
        ]);

        $this->actingAs($this->lead)->post(
            route('admin.projects.comments.store', $this->project),
            ['body' => 'Internal note'],
        )->assertSessionHasNoErrors();
        $comment = $this->project->comments()->firstOrFail();

        $leave = TeamLeaveApplication::create([
            'user_id' => $this->member->id,
            'from_date' => TeamLeaveApplication::dayKey($this->monday()),
            'to_date' => TeamLeaveApplication::dayKey($this->monday()),
            'reason' => 'Dentist',
            'status' => 'approved',
        ]);

        $events = [
            new MentionedInComment($comment, $this->lead),
            new TaskAssigned($task, 3, $this->lead),
            new TaskReassignedAway($task, $this->member, $this->lead),
            new TeamLeaveReviewed($leave),
            new TeamAbsenceFineCharged(new TeamAbsenceFine(['month' => '2026-10-01', 'absences' => 3, 'chargeable' => 1, 'allowance' => 2, 'total' => 500])),
            new MilestoneDueTomorrow($milestone),
            new ProjectOverdue($this->project, 2),
            new ClientAskedAQuestion($question),
        ];

        $this->assertCount(8, $events, 'the eight events are what this asserts about');

        foreach ($events as $event) {
            $this->assertFalse(
                PortalNotifier::notify($this->clientUser, $this->project, $event),
                $event::class.' was written to a client',
            );
        }

        $this->assertSame(0, $this->clientUser->notifications()->count());
    }

    /**
     * And in the wild: every flow run for real, on the client's own project.
     *
     * The team's counts are asserted too — a silence that comes from nothing
     * having happened proves nothing.
     */
    public function test_no_real_flow_leaves_a_client_a_notification(): void
    {
        // 1. A mention in the project discussion.
        $this->actingAs($this->lead)->post(
            route('admin.projects.comments.store', $this->project),
            ['body' => 'Taking this one @bilal.ahmed'],
        )->assertSessionHasNoErrors();

        // 2 and 3. An assignment, then a handover.
        $task = $this->makeTask($this->team, ['project_id' => $this->project->id]);
        $this->actingAs($this->lead)
            ->post(route('admin.tasks.assign.store', $task), ['user_id' => $this->member->id, 'days_allowed' => 3])
            ->assertRedirect();
        $this->actingAs($this->lead)
            ->post(route('admin.tasks.assign.store', $task), ['user_id' => $this->lead->id, 'days_allowed' => 2])
            ->assertRedirect();

        // 4. A leave decision.
        $this->actingAs($this->member)->post(route('admin.team-leave.store'), [
            'from_date' => $this->monday()->toDateString(),
            'to_date' => $this->monday()->toDateString(),
            'reason' => 'Dentist',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(
            route('admin.team-leave.review', TeamLeaveApplication::firstOrFail()),
            ['days' => [$this->monday()->toDateString()]],
        )->assertRedirect();

        // 5. An absence fine.
        $month = $this->monday()->copy()->startOfMonth();
        foreach ([0, 1, 2] as $offset) {
            TeamAttendance::create([
                'user_id' => $this->member->id,
                'date' => TeamAttendance::dayKey($month->copy()->addDays($offset)),
                'status' => 'absent',
            ]);
        }
        $this->artisan('attendance:charge-absent-fines', ['--month' => $month->toDateString()])->assertSuccessful();

        // 6 and 7. A milestone due tomorrow, and this project gone overdue —
        // both notices ABOUT the client's own project, which is exactly where a
        // leak would be most natural.
        ProjectMilestone::create([
            'project_id' => $this->project->id,
            'name' => 'Design sign-off',
            'due_date' => ProjectMilestone::dayKey($this->monday()->copy()->addDay()),
            'sort_order' => 1,
        ]);
        $this->project->update(['due_date' => Project::dayKey($this->monday()->copy()->subDays(2))]);
        $this->artisan('team:notify-deadlines')->assertSuccessful();

        // 8. The client's own question.
        $this->actingAs($this->clientUser)
            ->post(route('client.questions.store', $this->project), ['body' => 'When is it live?'])
            ->assertSessionHasNoErrors();

        // …and its answer.
        $this->actingAs($this->lead)->post(
            route('admin.projects.questions.answer', [$this->project, ClientQuestion::firstOrFail()]),
            ['answer_body' => 'Friday.'],
        )->assertRedirect();

        // THE CLIENT: nothing at all.
        $this->assertSame(0, $this->clientUser->notifications()->count(), implode(' ', [
            'A client was given a notification row. They have no bell and no notifications page;',
            'they learn an answer arrived by seeing the question marked answered on the project.',
        ]));

        // THE TEAM: named, not counted. A silence that came from nothing having
        // happened proves nothing, and a threshold would hide one event going
        // missing behind another firing twice.
        $this->assertSame([
            ClientAskedAQuestion::class,
            MilestoneDueTomorrow::class,
            ProjectOverdue::class,
        ], $this->sorted($this->lead), 'the lead did not receive what this run should have sent them');

        $this->assertSame([
            MentionedInComment::class,
            MilestoneDueTomorrow::class,
            ProjectOverdue::class,
            TaskAssigned::class,
            TaskReassignedAway::class,
            TeamAbsenceFineCharged::class,
            TeamLeaveReviewed::class,
        ], $this->sorted($this->member), 'the member did not receive what this run should have sent them');

        // Between the two of them, ALL EIGHT classes fired in this one run — and
        // none of them reached the client. Asserted on the union rather than on
        // a count per person, so an event that quietly stopped firing fails here
        // instead of being covered by another that fired twice.
        $this->assertCount(
            8,
            array_unique(array_merge($this->sorted($this->lead), $this->sorted($this->member))),
            'this run no longer exercises all eight events, so the client\'s silence proves less than it should',
        );
    }
}
