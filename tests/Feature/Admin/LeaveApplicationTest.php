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

    public function test_approve_writes_leave_rows_without_touching_present_days(): void
    {
        $leave = $this->makeLeave(); // today .. today+2

        $this->attendanceRow(today()->toDateString(), 'present'); // showed up anyway
        $this->attendanceRow(today()->addDay()->toDateString(), 'absent'); // marked absent

        $this->actingAs($this->admin)
            ->post("/admin/leaves/{$leave->id}/approve")
            ->assertSessionHas('success');

        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertSame($this->admin->id, $leave->reviewed_by);
        $this->assertNotNull($leave->reviewed_at);

        // Day 1: the student actually attended — stays present.
        $day1 = DailyAttendance::whereDate('date', today())->where('user_id', $this->student->id)->first();
        $this->assertSame('present', $day1->status);

        // Day 2: absent is overwritten to leave.
        $day2 = DailyAttendance::whereDate('date', today()->addDay())->where('user_id', $this->student->id)->first();
        $this->assertSame('leave', $day2->status);
        $this->assertSame('Approved leave', $day2->remarks);

        // Day 3: missing row is created as leave.
        $day3 = DailyAttendance::whereDate('date', today()->addDays(2))->where('user_id', $this->student->id)->first();
        $this->assertNotNull($day3);
        $this->assertSame('leave', $day3->status);
        $this->assertSame('Approved leave', $day3->remarks);
        $this->assertSame($this->admin->id, $day3->marked_by);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'leave_approved',
            'module' => 'leave_applications',
            'record_id' => $leave->id,
        ]);
    }

    public function test_approve_notifies_the_student(): void
    {
        $leave = $this->makeLeave();

        $this->actingAs($this->admin)->post("/admin/leaves/{$leave->id}/approve");

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->student->id,
            'type' => LeaveApplicationReviewed::class,
        ]);

        $data = $this->student->notifications()->first()->data;
        $this->assertSame('Leave approved', $data['title']);
        $this->assertSame('/attendance', $data['action_url']);
    }

    public function test_reject_stores_the_note_notifies_and_leaves_attendance_alone(): void
    {
        $leave = $this->makeLeave();

        $this->actingAs($this->admin)
            ->post("/admin/leaves/{$leave->id}/reject", ['review_note' => 'Too close to finals'])
            ->assertSessionHas('success');

        $leave->refresh();
        $this->assertSame('rejected', $leave->status);
        $this->assertSame('Too close to finals', $leave->review_note);
        $this->assertSame($this->admin->id, $leave->reviewed_by);

        $this->assertSame(0, DailyAttendance::count());

        $data = $this->student->notifications()->first()->data;
        $this->assertSame('Leave rejected', $data['title']);
        $this->assertStringContainsString('Too close to finals', $data['message']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'leave_rejected',
            'module' => 'leave_applications',
            'record_id' => $leave->id,
        ]);
    }

    public function test_non_pending_applications_cannot_be_reviewed_again(): void
    {
        $leave = $this->makeLeave();

        $this->actingAs($this->admin)->post("/admin/leaves/{$leave->id}/approve");

        $this->actingAs($this->admin)
            ->post("/admin/leaves/{$leave->id}/reject", ['review_note' => 'Changed my mind'])
            ->assertSessionHas('error');

        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertNull($leave->review_note);

        $this->actingAs($this->admin)
            ->post("/admin/leaves/{$leave->id}/approve")
            ->assertSessionHas('error');

        // Only the original review notified the student.
        $this->assertSame(1, $this->student->notifications()->count());
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
