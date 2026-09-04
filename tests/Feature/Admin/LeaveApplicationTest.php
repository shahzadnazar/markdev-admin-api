<?php

namespace Tests\Feature\Admin;

use App\Models\DailyAttendance;
use App\Models\LeaveApplication;
use App\Models\User;
use App\Notifications\LeaveApplicationReviewed;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaveApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->student = User::factory()->create(['name' => 'Aliya Khan']);
        $this->student->assignRole('student');
    }

    protected function makeLeave(array $overrides = []): LeaveApplication
    {
        return LeaveApplication::create(array_merge([
            'user_id' => $this->student->id,
            'from_date' => today()->toDateString(),
            'to_date' => today()->addDays(2)->toDateString(),
            'reason' => 'Family wedding out of town',
        ], $overrides));
    }

    protected function attendanceRow(string $date, string $status): DailyAttendance
    {
        return DailyAttendance::create([
            'user_id' => $this->student->id,
            'date' => $date,
            'status' => $status,
            'source' => 'manual',
            'marked_by' => $this->admin->id,
            'marked_at' => now(),
        ]);
    }

    /* ------------------------------ Student API ---------------------------- */

    public function test_student_can_apply_for_leave_via_api(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson('/api/v1/leaves', [
            'from_date' => today()->addDay()->toDateString(),
            'to_date' => today()->addDays(3)->toDateString(),
            'reason' => 'Family wedding out of town',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.days_count', 3)
            ->assertJsonPath('data.reason', 'Family wedding out of town');

        $this->assertDatabaseHas('leave_applications', [
            'user_id' => $this->student->id,
            'status' => 'pending',
        ]);
    }

    public function test_overlapping_application_is_rejected_with_422(): void
    {
        $this->makeLeave(['from_date' => today()->addDays(2)->toDateString(), 'to_date' => today()->addDays(4)->toDateString()]);

        Sanctum::actingAs($this->student);

        $this->postJson('/api/v1/leaves', [
            'from_date' => today()->addDays(4)->toDateString(),
            'to_date' => today()->addDays(6)->toDateString(),
            'reason' => 'Extending the trip',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from_date');

        $this->assertSame(1, LeaveApplication::count());
    }

    public function test_a_rejected_application_does_not_block_reapplying(): void
    {
        $this->makeLeave([
            'from_date' => today()->addDays(2)->toDateString(),
            'to_date' => today()->addDays(4)->toDateString(),
            'status' => 'rejected',
        ]);

        Sanctum::actingAs($this->student);

        $this->postJson('/api/v1/leaves', [
            'from_date' => today()->addDays(2)->toDateString(),
            'to_date' => today()->addDays(4)->toDateString(),
            'reason' => 'Reapplying with more detail',
        ])->assertCreated();
    }

    public function test_leave_dates_are_validated(): void
    {
        Sanctum::actingAs($this->student);

        // In the past
        $this->postJson('/api/v1/leaves', [
            'from_date' => today()->subDay()->toDateString(),
            'to_date' => today()->toDateString(),
            'reason' => 'Backdated',
        ])->assertUnprocessable()->assertJsonValidationErrors('from_date');

        // Ends before it starts
        $this->postJson('/api/v1/leaves', [
            'from_date' => today()->addDays(3)->toDateString(),
            'to_date' => today()->addDay()->toDateString(),
            'reason' => 'Backwards range',
        ])->assertUnprocessable()->assertJsonValidationErrors('to_date');

        // Too far ahead
        $this->postJson('/api/v1/leaves', [
            'from_date' => today()->addDays(59)->toDateString(),
            'to_date' => today()->addDays(90)->toDateString(),
            'reason' => 'Sabbatical',
        ])->assertUnprocessable()->assertJsonValidationErrors('to_date');
    }

    public function test_student_only_sees_their_own_applications(): void
    {
        $other = User::factory()->create();
        $other->assignRole('student');

        $this->makeLeave();
        LeaveApplication::create([
            'user_id' => $other->id,
            'from_date' => today()->toDateString(),
            'to_date' => today()->toDateString(),
            'reason' => 'Someone else',
        ]);

        Sanctum::actingAs($this->student);

        $this->getJson('/api/v1/leaves')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'Family wedding out of town');
    }

    /* ------------------------------ Admin review --------------------------- */

    public function test_admin_screen_lists_pending_applications(): void
    {
        $this->makeLeave();

        $this->actingAs($this->admin)->get('/admin/leaves')
            ->assertOk()
            ->assertSee('Leave requests')
            ->assertSee('Aliya Khan')
            ->assertSee('Family wedding out of town');
    }

    public function test_instructor_cannot_open_the_leave_screen(): void
    {
        $instructor = User::factory()->create();
        $instructor->assignRole('instructor');

        $this->actingAs($instructor)->get('/admin/leaves')->assertForbidden();
    }

    protected function review(LeaveApplication $leave, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post("/admin/leaves/{$leave->id}/review", $payload);
    }

    public function test_part_of_a_range_can_be_approved_and_the_rest_declined(): void
    {
        // Five days, three approved.
        $leave = $this->makeLeave(['to_date' => today()->addDays(4)->toDateString()]);
        $approved = [
            today()->toDateString(),
            today()->addDay()->toDateString(),
            today()->addDays(3)->toDateString(),
        ];

        $this->review($leave, ['days' => $approved, 'review_note' => 'The other two clash with the assessment.'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $leave->refresh();
        $this->assertSame('partially_approved', $leave->status);
        $this->assertSame(3, $leave->decisions()->where('status', 'approved')->count());
        $this->assertSame(2, $leave->decisions()->where('status', 'declined')->count());
        $this->assertEqualsCanonicalizing($approved, $leave->approvedDates());

        // Reviewing decides; it never writes the register.
        $this->assertSame(0, DailyAttendance::count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'leave_reviewed',
            'module' => 'leave_applications',
            'record_id' => $leave->id,
        ]);
    }

    public function test_declining_any_day_without_a_note_is_rejected(): void
    {
        $leave = $this->makeLeave(); // today .. today+2

        $this->review($leave, ['days' => [today()->toDateString()]])
            ->assertSessionHasErrors('review_note');

        $this->review($leave, ['decline_all' => 1])
            ->assertSessionHasErrors('review_note');

        $leave->refresh();
        $this->assertSame('pending', $leave->status);
        $this->assertSame(0, $leave->decisions()->count());
    }

    public function test_approving_every_day_needs_no_note(): void
    {
        $leave = $this->makeLeave();
        $all = collect($leave->days())->map->toDateString()->all();

        $this->review($leave, ['days' => $all])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertNull($leave->review_note);
        $this->assertSame(3, $leave->decisions()->where('status', 'approved')->count());
    }

    public function test_decline_all_records_every_day_as_declined(): void
    {
        $leave = $this->makeLeave();

        $this->review($leave, ['decline_all' => 1, 'review_note' => 'Too close to finals'])
            ->assertSessionHas('success');

        $leave->refresh();
        $this->assertSame('rejected', $leave->status);
        $this->assertSame('Too close to finals', $leave->review_note);
        $this->assertSame(3, $leave->decisions()->where('status', 'declined')->count());
        $this->assertSame([], $leave->approvedDates());
        $this->assertSame(0, DailyAttendance::count());
    }

    public function test_the_student_is_told_which_days_were_approved(): void
    {
        $leave = $this->makeLeave();

        $this->review($leave, [
            'days' => [today()->toDateString()],
            'review_note' => 'Only the first day.',
        ]);

        $data = $this->student->notifications()->first()->data;
        $this->assertSame('Leave partly approved', $data['title']);
        $this->assertStringContainsString('1 day(s) were approved', $data['message']);
        $this->assertStringContainsString('Only the first day.', $data['message']);

        // And the portal sees the per-day verdicts, not one rollup.
        Sanctum::actingAs($this->student);
        $this->getJson('/api/v1/leaves')->assertOk()
            ->assertJsonPath('data.0.status', 'partially_approved')
            ->assertJsonPath('data.0.approved_days', 1)
            ->assertJsonPath('data.0.declined_days', 2)
            ->assertJsonPath('data.0.days.0.status', 'approved')
            ->assertJsonPath('data.0.days.1.status', 'declined')
            ->assertJsonPath('data.0.review_note', 'Only the first day.');
    }

    public function test_non_pending_applications_cannot_be_reviewed_again(): void
    {
        $leave = $this->makeLeave();
        $all = collect($leave->days())->map->toDateString()->all();

        $this->review($leave, ['days' => $all]);

        $this->review($leave, ['decline_all' => 1, 'review_note' => 'Changed my mind'])
            ->assertSessionHas('error');

        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertNull($leave->review_note);

        // Only the original review notified the student.
        $this->assertSame(1, $this->student->notifications()->count());
    }

    /* ------------------- Approval reaches the register at close ------------- */

    public function test_an_approved_day_becomes_leave_when_that_day_closes(): void
    {
        $leave = $this->makeLeave(['from_date' => today()->toDateString(), 'to_date' => today()->toDateString()]);
        $this->review($leave, ['days' => [today()->toDateString()]]);

        $this->assertSame(0, DailyAttendance::count());

        $this->artisan('attendance:close-day')->assertSuccessful();

        $row = DailyAttendance::onDate(today())->where('user_id', $this->student->id)->first();
        $this->assertSame('leave', $row->status);
        $this->assertSame('Approved leave', $row->remarks);
    }

    public function test_a_student_who_turned_up_stays_present(): void
    {
        $leave = $this->makeLeave(['from_date' => today()->toDateString(), 'to_date' => today()->toDateString()]);
        $this->review($leave, ['days' => [today()->toDateString()]]);

        $this->attendanceRow(today()->toDateString(), 'present');

        $this->artisan('attendance:close-day')->assertSuccessful();

        $row = DailyAttendance::onDate(today())->where('user_id', $this->student->id)->first();
        $this->assertSame('present', $row->status);
    }

    public function test_a_declined_day_becomes_absent_when_that_day_closes(): void
    {
        $leave = $this->makeLeave(['from_date' => today()->toDateString(), 'to_date' => today()->toDateString()]);
        $this->review($leave, ['decline_all' => 1, 'review_note' => 'Not this time']);

        $this->artisan('attendance:close-day')->assertSuccessful();

        $row = DailyAttendance::onDate(today())->where('user_id', $this->student->id)->first();
        $this->assertSame('absent', $row->status);
    }

    public function test_a_future_approval_writes_nothing_until_that_day_closes(): void
    {
        $future = today()->addDays(3);
        $leave = $this->makeLeave(['from_date' => $future->toDateString(), 'to_date' => $future->toDateString()]);
        $this->review($leave, ['days' => [$future->toDateString()]]);

        // Closing today says nothing about a day that has not happened.
        $this->artisan('attendance:close-day')->assertSuccessful();
        $this->assertSame(0, DailyAttendance::onDate($future)->count());

        $this->artisan('attendance:close-day', ['--date' => $future->toDateString()])->assertSuccessful();
        $this->assertSame('leave', DailyAttendance::onDate($future)->where('user_id', $this->student->id)->value('status'));
    }

    public function test_closing_the_same_day_twice_changes_nothing(): void
    {
        $leave = $this->makeLeave(['from_date' => today()->toDateString(), 'to_date' => today()->toDateString()]);
        $this->review($leave, ['days' => [today()->toDateString()]]);

        $this->artisan('attendance:close-day')->assertSuccessful();
        $first = DailyAttendance::onDate(today())->where('user_id', $this->student->id)->first();

        $this->artisan('attendance:close-day')->assertSuccessful();
        $second = DailyAttendance::onDate(today())->where('user_id', $this->student->id)->first();

        $this->assertSame(1, DailyAttendance::onDate(today())->where('user_id', $this->student->id)->count());
        $this->assertSame($first->status, $second->status);
        $this->assertEquals($first->updated_at, $second->updated_at);
    }

    /* ---------------------------- Attendance math -------------------------- */

    public function test_student_summary_rate_counts_leave_as_attended(): void
    {
        $this->attendanceRow(today()->subDays(2)->toDateString(), 'present');
        $this->attendanceRow(today()->subDay()->toDateString(), 'leave');
        $this->attendanceRow(today()->toDateString(), 'absent');

        // (present + late + leave) / total = 2/3 — leave no longer drags the rate down.
        $this->actingAs($this->admin)
            ->get('/admin/attendance/daily/'.$this->student->id.'?range=all')
            ->assertOk()
            ->assertSee('66.7');
    }

    public function test_dashboard_exposes_leave_stats(): void
    {
        $this->makeLeave(['status' => 'approved']); // covers today
        $this->makeLeave([
            'from_date' => today()->addDays(10)->toDateString(),
            'to_date' => today()->addDays(11)->toDateString(),
        ]); // pending

        Sanctum::actingAs($this->student);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.stats.approved_leave_today', true)
            ->assertJsonPath('data.stats.pending_leaves', 1);
    }
}
