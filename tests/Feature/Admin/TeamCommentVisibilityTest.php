<?php

namespace Tests\Feature\Admin;

use App\Models\Project;
use App\Models\Task;
use App\Models\TeamProjectComment;
use App\Models\TeamTaskComment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * A conversation is exactly as visible as the work it hangs off.
 *
 * Everything here goes through the EXISTING scopes — Project::scopeVisibleTo
 * and Task::scopeVisibleTo — so a discussion cannot be reachable where its
 * project is not. 404 rather than 403 throughout, matching projects.show: a
 * refusal would confirm the work exists, which is already a fact about
 * somebody else's client engagement.
 */
class TeamCommentVisibilityTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected User $lead;

    protected User $otherLead;

    protected User $member;

    protected Project $ours;

    protected Project $theirs;

    protected Task $ourTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();

        $this->lead = $this->roleUser('team-lead', ['name' => 'Ayesha Khan']);
        $this->otherLead = $this->roleUser('team-lead', ['name' => 'Chandni Rao']);
        $this->member = $this->roleUser('team', ['name' => 'Bilal Ahmed']);

        $ourTeam = $this->makeTeam('Web', $this->lead, [$this->lead, $this->member]);
        $theirTeam = $this->makeTeam('Graphics', $this->otherLead, [$this->otherLead]);

        $this->ours = $this->makeProject($ourTeam);
        $this->theirs = $this->makeProject($theirTeam);

        $this->ourTask = $this->makeTask($ourTeam, ['project_id' => $this->ours->id]);
        $this->makeStint($this->ourTask, $this->member, 3, $this->monday());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ---------------------------- Another team's ---------------------------- */

    public function test_a_lead_cannot_read_another_teams_project_discussion(): void
    {
        TeamProjectComment::create([
            'project_id' => $this->theirs->id,
            'user_id' => $this->otherLead->id,
            'body' => 'Something confidential about their client',
        ]);

        // The discussion lives on the project page, which is already scoped.
        $this->actingAs($this->lead)
            ->get(route('admin.projects.show', $this->theirs))
            ->assertNotFound();
    }

    public function test_a_lead_cannot_post_in_another_teams_project_discussion(): void
    {
        $this->actingAs($this->lead)
            ->post(route('admin.projects.comments.store', $this->theirs), ['body' => 'Butting in'])
            ->assertNotFound();

        $this->assertSame(0, TeamProjectComment::count());
    }

    public function test_a_lead_cannot_delete_a_comment_on_another_teams_project(): void
    {
        $comment = TeamProjectComment::create([
            'project_id' => $this->theirs->id,
            'user_id' => $this->otherLead->id,
            'body' => 'Theirs',
        ]);

        // 404 before the ownership question is even asked: they cannot see the
        // project, so as far as they are concerned there is nothing there.
        $this->actingAs($this->lead)
            ->delete(route('admin.projects.comments.destroy', [$this->theirs, $comment]))
            ->assertNotFound();
    }

    public function test_a_comment_from_another_project_is_not_found_on_this_one(): void
    {
        $theirComment = TeamProjectComment::create([
            'project_id' => $this->theirs->id,
            'user_id' => $this->otherLead->id,
            'body' => 'Theirs',
        ]);

        $this->actingAs($this->otherLead)
            ->delete(route('admin.projects.comments.destroy', [$this->ours, $theirComment]))
            ->assertNotFound();
    }

    /* -------------------------------- Tasks --------------------------------- */

    public function test_a_task_comment_follows_the_task_scope(): void
    {
        $stranger = $this->roleUser('team', ['name' => 'Stranger Person']);

        // A member sees the tasks they hold a stint on, and no others.
        $this->actingAs($stranger)
            ->post(route('admin.tasks.comments.store', $this->ourTask), ['body' => 'Hello'])
            ->assertNotFound();

        $this->actingAs($this->member)
            ->post(route('admin.tasks.comments.store', $this->ourTask), ['body' => 'Hello'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, TeamTaskComment::count());
    }

    /* ------------------------- Outside the portal --------------------------- */

    /**
     * A client and an academy role reach none of it.
     *
     * Both are refused at the group door — neither holds a team permission nor
     * a team role — so this is the same answer for the channel, the project
     * discussion, the task comments and the files.
     */
    public function test_a_client_and_an_instructor_reach_no_surface_at_all(): void
    {
        foreach (['client', 'instructor'] as $role) {
            $user = $this->roleUser($role);

            $this->actingAs($user)->get(route('admin.team-channel.index'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.team-channel.store'), ['body' => 'Hi'])->assertForbidden();
            $this->actingAs($user)->get(route('admin.projects.show', $this->ours))->assertForbidden();
            $this->actingAs($user)->post(route('admin.projects.comments.store', $this->ours), ['body' => 'Hi'])->assertForbidden();
            $this->actingAs($user)->post(route('admin.tasks.comments.store', $this->ourTask), ['body' => 'Hi'])->assertForbidden();
            $this->actingAs($user)->post(route('admin.team-files.store', ['project', $this->ours->id]))->assertForbidden();
        }
    }

    /* ------------------------------ The channel ----------------------------- */

    public function test_every_team_role_reads_the_channel(): void
    {
        foreach ([$this->lead, $this->member, $this->otherLead] as $user) {
            $this->actingAs($user)->get(route('admin.team-channel.index'))->assertOk();
        }
    }
}
