<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamChannelMessage;
use App\Models\TeamCommentMention;
use App\Models\TeamProjectComment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * The three staff conversations: threading, mentions, and who may remove words.
 */
class TeamCommentTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected User $admin;

    protected User $lead;

    protected User $member;

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
        $this->project = $this->makeProject($this->team);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Named `say` rather than `post`, which is TestCase's own. */
    protected function say(User $as, string $body, ?int $parentId = null)
    {
        return $this->actingAs($as)->post(
            route('admin.projects.comments.store', $this->project),
            array_filter(['body' => $body, 'parent_id' => $parentId]),
        );
    }

    /* ------------------------------- Threading ------------------------------ */

    public function test_a_reply_cannot_be_replied_to(): void
    {
        $this->say($this->lead, 'Where are we on the checkout?')->assertSessionHasNoErrors();
        $opener = TeamProjectComment::firstOrFail();

        $this->say($this->member, 'Halfway.', $opener->id)->assertSessionHasNoErrors();
        $reply = TeamProjectComment::where('parent_id', $opener->id)->firstOrFail();

        $this->say($this->lead, 'And the rest?', $reply->id)->assertSessionHasErrors('parent_id');

        // And straight past the form: two levels is a screen anyone can read,
        // recursion is a screen nobody can, so the rule lives on `saving`.
        $this->expectException(ValidationException::class);

        TeamProjectComment::create([
            'project_id' => $this->project->id,
            'user_id' => $this->lead->id,
            'parent_id' => $reply->id,
            'body' => 'Straight past the form',
        ]);
    }

    public function test_deleting_a_thread_opener_leaves_its_replies_readable(): void
    {
        $this->say($this->lead, 'Opening question')->assertSessionHasNoErrors();
        $opener = TeamProjectComment::firstOrFail();

        $this->say($this->member, 'A useful answer', $opener->id)->assertSessionHasNoErrors();

        $this->actingAs($this->lead)
            ->delete(route('admin.projects.comments.destroy', [$this->project, $opener]))
            ->assertSessionHasNoErrors();

        // A thread is other people's words. Removing the opener must not take
        // the rest with it.
        $this->assertSoftDeleted('team_project_comments', ['id' => $opener->id]);
        $this->assertSame(1, TeamProjectComment::where('parent_id', $opener->id)->count());
        $this->assertSame('A useful answer', TeamProjectComment::where('parent_id', $opener->id)->value('body'));
    }

    /* -------------------------------- Mentions ------------------------------ */

    public function test_a_mention_of_a_teammate_is_stored(): void
    {
        $this->say($this->lead, 'Can you look at this @bilal.ahmed?')->assertSessionHasNoErrors();

        $comment = TeamProjectComment::firstOrFail();

        $this->assertSame([$this->member->id], $comment->mentions->pluck('user_id')->all());
    }

    /**
     * A mention of somebody outside the set is plain text, and NOT an error.
     *
     * People type @ for other reasons — an email address, a handle from
     * somewhere else — and refusing the comment over it would be a worse
     * answer than quietly not linking it.
     */
    public function test_a_mention_of_somebody_outside_the_set_stores_nothing_and_does_not_error(): void
    {
        $outsider = $this->roleUser('team', ['name' => 'Outsider Person']);

        $this->say($this->lead, 'Asking @outsider.person and also @nobody.at.all')
            ->assertSessionHasNoErrors();

        $comment = TeamProjectComment::firstOrFail();

        $this->assertSame(0, $comment->mentions()->count());
        $this->assertSame(0, TeamCommentMention::count());
        // The text is untouched — it is still there to read.
        $this->assertStringContainsString('@outsider.person', $comment->body);
    }

    public function test_the_channel_lets_a_mention_cross_a_team(): void
    {
        $other = $this->roleUser('team', ['name' => 'Chandni Rao']);
        $this->makeTeam('Graphics', $other, [$other]);

        $this->actingAs($this->member)
            ->post(route('admin.team-channel.store'), ['body' => 'Anyone free? @chandni.rao'])
            ->assertSessionHasNoErrors();

        // The channel is the one surface where a mention crosses a team
        // boundary, which is the point of having it.
        $this->assertSame([$other->id], TeamChannelMessage::firstOrFail()->mentions->pluck('user_id')->all());
    }

    public function test_an_edit_rewrites_the_mention_rows(): void
    {
        $this->say($this->lead, 'Hello @bilal.ahmed')->assertSessionHasNoErrors();
        $comment = TeamProjectComment::firstOrFail();

        $this->actingAs($this->lead)->put(
            route('admin.projects.comments.update', [$this->project, $comment]),
            ['body' => 'Never mind'],
        )->assertSessionHasNoErrors();

        // A mention removed from the text is a mention that is no longer there.
        $this->assertSame(0, $comment->fresh()->mentions()->count());
    }

    public function test_mentioning_yourself_records_nothing(): void
    {
        $this->say($this->lead, 'Note to self @ayesha.khan')->assertSessionHasNoErrors();

        $this->assertSame(0, TeamCommentMention::count());
    }

    /* ----------------------------- Announcements ---------------------------- */

    public function test_only_an_admin_can_post_an_announcement(): void
    {
        foreach ([$this->lead, $this->member] as $user) {
            $this->actingAs($user)
                ->post(route('admin.team-channel.store'), ['body' => 'Everyone listen', 'is_announcement' => 1])
                ->assertSessionHasErrors('is_announcement');
        }

        $this->assertSame(0, TeamChannelMessage::count());

        $this->actingAs($this->admin)
            ->post(route('admin.team-channel.store'), ['body' => 'Office closed Friday', 'is_announcement' => 1])
            ->assertSessionHasNoErrors();

        $this->assertTrue(TeamChannelMessage::firstOrFail()->is_announcement);
    }

    public function test_an_ordinary_message_is_open_to_everyone_in_the_portal(): void
    {
        $this->actingAs($this->member)
            ->post(route('admin.team-channel.store'), ['body' => 'Morning all'])
            ->assertSessionHasNoErrors();

        $this->assertFalse(TeamChannelMessage::firstOrFail()->is_announcement);
    }

    public function test_announcements_are_pinned_above_the_conversation(): void
    {
        $this->actingAs($this->member)->post(route('admin.team-channel.store'), ['body' => 'Morning all']);
        $this->actingAs($this->admin)->post(route('admin.team-channel.store'), ['body' => 'Office closed Friday', 'is_announcement' => 1]);

        $this->assertSame(
            ['Office closed Friday', 'Morning all'],
            TeamChannelMessage::threads()->pinnedFirst()->pluck('body')->all(),
        );
    }

    /* ------------------------- Editing and deleting -------------------------- */

    public function test_only_the_author_may_edit(): void
    {
        $this->say($this->member, 'Mine')->assertSessionHasNoErrors();
        $comment = TeamProjectComment::firstOrFail();

        foreach ([$this->lead, $this->admin] as $other) {
            $this->actingAs($other)->put(
                route('admin.projects.comments.update', [$this->project, $comment]),
                ['body' => 'Edited by somebody else'],
            )->assertForbidden();
        }

        $this->assertSame('Mine', $comment->fresh()->body);
    }

    /**
     * A team-lead is not a moderator.
     *
     * They run the work; deciding whose words stay is a different job, and one
     * nobody should be handed about the person whose delivery they are scored
     * on.
     */
    public function test_a_team_lead_cannot_delete_a_members_comment_but_a_super_admin_can(): void
    {
        $this->say($this->member, 'Something the lead dislikes')->assertSessionHasNoErrors();
        $comment = TeamProjectComment::firstOrFail();

        $this->actingAs($this->lead)
            ->delete(route('admin.projects.comments.destroy', [$this->project, $comment]))
            ->assertForbidden();

        $this->assertNotSoftDeleted('team_project_comments', ['id' => $comment->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.projects.comments.destroy', [$this->project, $comment]))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('team_project_comments', ['id' => $comment->id]);
    }

    public function test_both_outcomes_are_audited_with_who_and_whose(): void
    {
        $this->say($this->member, 'Mine to delete')->assertSessionHasNoErrors();
        $own = TeamProjectComment::firstOrFail();

        $this->actingAs($this->member)
            ->delete(route('admin.projects.comments.destroy', [$this->project, $own]));

        $byOwner = AuditLog::where('module', 'team_project_comments')->where('action', 'deleted')->latest('id')->firstOrFail();
        $this->assertSame($this->member->id, $byOwner->user_id);
        $this->assertTrue($byOwner->old_values['by_owner'] ?? $byOwner->new_values['by_owner']);

        $this->say($this->member, 'Somebody else deletes this')->assertSessionHasNoErrors();
        $other = TeamProjectComment::orderByDesc('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->delete(route('admin.projects.comments.destroy', [$this->project, $other]));

        $bySuper = AuditLog::where('module', 'team_project_comments')->where('action', 'deleted')->latest('id')->firstOrFail();

        // "Root Admin deleted Bilal's message" and "Bilal deleted his own" are
        // two visibly different rows, not one row read twice.
        $this->assertSame($this->admin->id, $bySuper->user_id);
        $this->assertFalse($bySuper->old_values['by_owner'] ?? $bySuper->new_values['by_owner']);
        $this->assertSame('Bilal Ahmed', $bySuper->old_values['author_name'] ?? $bySuper->new_values['author_name']);
    }

    public function test_an_edit_is_audited_with_the_old_and_new_body(): void
    {
        $this->say($this->member, 'First draft')->assertSessionHasNoErrors();
        $comment = TeamProjectComment::firstOrFail();

        $this->actingAs($this->member)->put(
            route('admin.projects.comments.update', [$this->project, $comment]),
            ['body' => 'Second draft'],
        )->assertSessionHasNoErrors();

        $entry = AuditLog::where('module', 'team_project_comments')->where('action', 'updated')->latest('id')->firstOrFail();

        $this->assertSame('First draft', $entry->old_values['body']);
        $this->assertSame('Second draft', $entry->new_values['body']);
    }

    /* ---------------------------- Never the client -------------------------- */

    public function test_no_comment_table_carries_a_client_visibility_flag(): void
    {
        // Not a style preference: a toggle is how somebody shows a client the
        // wrong thing at 11pm, so the safe state is the only state.
        foreach (['team_project_comments', 'team_task_comments', 'team_channel_messages'] as $table) {
            $this->assertFalse(
                Schema::hasColumn($table, 'is_client_visible'),
                "{$table} has grown a client-visibility flag. Anything a client sees is written deliberately in the client portal, not toggled here.",
            );
        }
    }
}
