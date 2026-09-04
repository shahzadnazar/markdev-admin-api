<?php

namespace Tests\Feature\Admin;

use App\Models\AttendanceSlot;
use App\Models\DailyAttendance;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CloseAttendanceDayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function student(array $attributes = []): User
    {
        $student = User::factory()->create($attributes);
        $student->assignRole('student');

        return $student;
    }

    protected function mark(User $student, string $date, string $status): DailyAttendance
    {
        return DailyAttendance::create([
            'user_id' => $student->id,
            'date' => $date,
            'status' => $status,
            'source' => 'manual',
            'marked_at' => now(),
        ]);
    }

    protected function statusOn(User $student, string $date): ?string
    {
        return DailyAttendance::onDate($date)->where('user_id', $student->id)->value('status');
    }

    public function test_a_student_with_no_row_is_marked_absent(): void
    {
        $student = $this->student();
        $today = now()->toDateString();

        $this->artisan('attendance:close-day')->assertSuccessful();

        $this->assertSame('absent', $this->statusOn($student, $today));
        $this->assertDatabaseHas('daily_attendance_records', [
            'user_id' => $student->id,
            'status' => 'absent',
            'source' => 'auto',
        ]);
    }

    public function test_present_late_and_leave_are_never_overwritten(): void
    {
        $today = now()->toDateString();

        $present = $this->student();
        $late = $this->student();
        $leave = $this->student();
        $unmarked = $this->student();

        $this->mark($present, $today, 'present');
        $this->mark($late, $today, 'late');
        $this->mark($leave, $today, 'leave');

        $this->artisan('attendance:close-day')->assertSuccessful();

        $this->assertSame('present', $this->statusOn($present, $today));
        $this->assertSame('late', $this->statusOn($late, $today));
        $this->assertSame('leave', $this->statusOn($leave, $today));
        $this->assertSame('absent', $this->statusOn($unmarked, $today));
    }

    public function test_a_day_held_open_as_pending_is_settled(): void
    {
        $student = $this->student();
        $today = now()->toDateString();
        $this->mark($student, $today, DailyAttendance::PENDING);

        $this->artisan('attendance:close-day')->assertSuccessful();

        $this->assertSame('absent', $this->statusOn($student, $today));
    }

    public function test_an_inactive_student_collects_no_absence(): void
    {
        $student = $this->student(['is_active' => false]);

        $this->artisan('attendance:close-day')->assertSuccessful();

        $this->assertNull($this->statusOn($student, now()->toDateString()));
    }

    public function test_a_slot_that_does_not_run_today_collects_no_absence(): void
    {
        // A slot on every weekday except today, so its student is not expected.
        $today = now();
        $otherDays = array_values(array_diff(array_keys(AttendanceSlot::DAYS), [$today->dayOfWeekIso]));

        $slot = AttendanceSlot::create([
            'name' => 'Not today',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'days' => $otherDays,
            'late_after_minutes' => 15,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $onSlot = $this->student();
        $onSlot->studentProfile()->create(['reg_no' => 'MD-SLOT-1', 'attendance_slot_id' => $slot->id]);

        // A student with no slot follows the academy-wide day, as before.
        $noSlot = $this->student();

        $this->artisan('attendance:close-day')->assertSuccessful();

        $this->assertNull($this->statusOn($onSlot, $today->toDateString()));
        $this->assertSame('absent', $this->statusOn($noSlot, $today->toDateString()));
    }

    public function test_catch_up_settles_the_days_a_missed_run_left_open(): void
    {
        $student = $this->student();
        $days = collect(range(0, 3))->map(fn (int $back) => now()->subDays($back)->toDateString());

        // The student turned up two days ago; the rest nobody marked.
        $this->mark($student, $days[2], 'present');

        $this->artisan('attendance:close-day', ['--catch-up' => 3])->assertSuccessful();

        $this->assertSame('absent', $this->statusOn($student, $days[0]));
        $this->assertSame('absent', $this->statusOn($student, $days[1]));
        $this->assertSame('present', $this->statusOn($student, $days[2]));
        $this->assertSame('absent', $this->statusOn($student, $days[3]));

        // Four days settled, and a second run finds nothing left to do.
        $this->assertSame(4, DailyAttendance::where('user_id', $student->id)->count());
        $this->artisan('attendance:close-day', ['--catch-up' => 3])->assertSuccessful();
        $this->assertSame(4, DailyAttendance::where('user_id', $student->id)->count());
        $this->assertSame('present', $this->statusOn($student, $days[2]));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $student = $this->student();

        $this->artisan('attendance:close-day', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, DailyAttendance::where('user_id', $student->id)->count());
    }

    public function test_a_named_date_closes_that_day_and_no_other(): void
    {
        $student = $this->student();
        $target = Carbon::parse('2026-08-19')->toDateString();

        $this->artisan('attendance:close-day', ['--date' => $target])->assertSuccessful();

        $this->assertSame('absent', $this->statusOn($student, $target));
        $this->assertNull($this->statusOn($student, now()->toDateString()));
    }
}
