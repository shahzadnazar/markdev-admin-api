<?php

namespace Tests\Feature\Admin;

use App\Models\LeaveApplication;
use App\Models\TeamLeaveApplication;
use App\Models\TeamLeaveApplicationDay;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * Team leave: anybody applies, only an admin reviews.
 */
class TeamLeaveTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected User $admin;

    protected User $lead;

    protected User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();

        $this->admin = $this->roleUser('super-admin');
        $this->lead = $this->roleUser('team-lead', ['name' => 'Lead Person']);
        $this->member = $this->roleUser('team', ['name' => 'Member Person']);
        $this->makeTeam('Web', $this->lead, [$this->lead, $this->member]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function apply(User $user, ?Carbon $from = null, ?Carbon $to = null)
    {
        return $this->actingAs($user)->post(route('admin.team-leave.store'), [
            'from_date' => ($from ?? $this->monday())->toDateString(),
            'to_date' => ($to ?? $this->monday())->toDateString(),
            'reason' => 'Family matter',
        ]);
    }

    /* -------------------------------- Applying ------------------------------ */

    public function test_a_member_applies_and_the_days_are_reserved(): void
    {
        $this->apply($this->member)->assertSessionHasNoErrors();

        $leave = TeamLeaveApplication::firstOrFail();

        $this->assertSame('pending', $leave->status);
        // A pending day is reserved while it waits: several requests filed at
        // once must not all fit inside one allowance.
        $this->assertSame(1, $leave->decisions()->where('status', TeamLeaveApplicationDay::PENDING)->count());
    }

    public function test_weekends_inside_a_range_never_become_days(): void
    {
        // Friday to the following Monday: four calendar days, two working ones.
        $this->apply($this->member, $this->monday()->copy()->addDays(4), $this->monday()->copy()->addDays(7))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, TeamLeaveApplication::firstOrFail()->decisions()->count());
    }

    public function test_an_application_beyond_the_allowance_is_refused(): void
    {
        // Two days is the default allowance; asking for three is one too many.
        $this->apply($this->member, $this->monday(), $this->monday()->copy()->addDays(2))
            ->assertSessionHasErrors('from_date');

        $this->assertSame(0, TeamLeaveApplication::count());
    }

    /* -------------------------------- Reviewing ----------------------------- */

    /**
     * A lead neither approves nor declines — including their own application.
     *
     * Pay and attendance are not a lead's job here, and a lead who is SCORED on
     * their team's delivery should not be the one deciding whether that team
     * gets time off. Their own application is the same conflict with the
     * paperwork removed.
     */
    public function test_a_team_lead_cannot_review_anything(): void
    {
        $this->apply($this->member)->assertSessionHasNoErrors();
        $leave = TeamLeaveApplication::firstOrFail();

        $this->actingAs($this->lead)->get(route('admin.team-leave.index'))->assertForbidden();

        $this->actingAs($this->lead)
            ->post(route('admin.team-leave.review', $leave), ['decline_all' => 1, 'review_note' => 'No'])
            ->assertForbidden();

        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_a_team_lead_cannot_review_their_own_application(): void
    {
        $this->apply($this->lead)->assertSessionHasNoErrors();
        $own = TeamLeaveApplication::firstOrFail();

        $this->actingAs($this->lead)
            ->post(route('admin.team-leave.review', $own), ['days' => [$this->monday()->toDateString()]])
            ->assertForbidden();

        $this->assertSame('pending', $own->fresh()->status);
    }

    public function test_an_admin_approves_every_day(): void
    {
        $this->apply($this->member, $this->monday(), $this->monday()->copy()->addDay())
            ->assertSessionHasNoErrors();
        $leave = TeamLeaveApplication::firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.team-leave.review', $leave), [
                'days' => [$this->monday()->toDateString(), $this->monday()->copy()->addDay()->toDateString()],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $leave->fresh()->status);
        $this->assertSame(2, $leave->decisions()->where('status', TeamLeaveApplicationDay::APPROVED)->count());
    }

    public function test_a_partial_approval_declines_the_rest(): void
    {
        $this->apply($this->member, $this->monday(), $this->monday()->copy()->addDay())
            ->assertSessionHasNoErrors();
        $leave = TeamLeaveApplication::firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.team-leave.review', $leave), [
                'days' => [$this->monday()->toDateString()],
                'review_note' => 'The second day is the client demo.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('partially_approved', $leave->fresh()->status);
        $this->assertSame(1, $leave->decisions()->where('status', TeamLeaveApplicationDay::DECLINED)->count());
    }

    /* -------------------------------- The reason ---------------------------- */

    public function test_a_decline_without_a_reason_is_refused(): void
    {
        $this->apply($this->member)->assertSessionHasNoErrors();
        $leave = TeamLeaveApplication::firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.team-leave.review', $leave), ['decline_all' => 1])
            ->assertSessionHasErrors('review_note');

        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_the_reason_reaches_the_member(): void
    {
        $this->apply($this->member)->assertSessionHasNoErrors();
        $leave = TeamLeaveApplication::firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.team-leave.review', $leave), [
                'decline_all' => 1,
                'review_note' => 'We need you for the client demo that day.',
            ])
            ->assertSessionHasNoErrors();

        // Somebody told "no" is owed the reason, which is why it is required.
        $this->actingAs($this->member)->get(route('admin.team-leave.mine'))
            ->assertOk()
            ->assertSee('We need you for the client demo that day.');
    }

    public function test_a_full_approval_needs_no_note(): void
    {
        $this->apply($this->member)->assertSessionHasNoErrors();
        $leave = TeamLeaveApplication::firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.team-leave.review', $leave), ['days' => [$this->monday()->toDateString()]])
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $leave->fresh()->status);
    }

    /* ---------------------------- Separate tables --------------------------- */

    public function test_team_leave_never_lands_in_the_academy_table(): void
    {
        $this->apply($this->member)->assertSessionHasNoErrors();

        $this->assertSame(1, TeamLeaveApplication::count());
        $this->assertSame(0, LeaveApplication::count());
    }
}
