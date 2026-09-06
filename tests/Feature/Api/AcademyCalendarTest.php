<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceSlot;
use App\Models\BiometricDevice;
use App\Models\DailyAttendance;
use App\Models\Holiday;
use App\Models\Setting;
use App\Models\User;
use App\Support\AbsenceFine;
use Illuminate\Support\Carbon;

/**
 * Days the academy is closed, and what the register does with them.
 *
 * A day nobody is expected on must not become an absence, because since
 * 459f3cc an absence is billable — so every assertion here is ultimately about
 * money, not presentation.
 */
class AcademyCalendarTest extends ApiTestCase
{
    /** A Wednesday, so "this week" has an unambiguous Saturday and Monday. */
    protected const WEDNESDAY = '2026-09-16';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::WEDNESDAY.' 20:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function workingDays(array $days): void
    {
        Setting::updateOrCreate(['key' => 'academy_working_days'], ['value' => $days, 'group' => 'general']);
        Setting::forgetCached();
    }

    protected function slot(array $days): AttendanceSlot
    {
        return AttendanceSlot::create([
            'name' => 'Slot '.uniqid(),
            'start_time' => '09:00:00',
            'end_time' => '13:00:00',
            'days' => $days,
            'late_after_minutes' => 15,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    protected function studentOn(?AttendanceSlot $slot, ?string $biometricId = null): User
    {
        $student = User::factory()->create(['biometric_id' => $biometricId ?? uniqid('bio-')]);
        $student->assignRole('student');
        $student->studentProfile()->create([
            'reg_no' => 'MD-'.uniqid(),
            'attendance_slot_id' => $slot?->id,
        ]);

        return $student->fresh();
    }

    protected function holiday(string $date, string $name = 'Eid ul-Fitr'): Holiday
    {
        return Holiday::create(['date' => $date, 'name' => $name]);
    }

    protected function close(string $date): void
    {
        $this->artisan('attendance:close-day', ['--date' => $date])->assertSuccessful();
    }

    protected function rowFor(User $student, string $date): ?DailyAttendance
    {
        return DailyAttendance::where('user_id', $student->id)->onDate($date)->first();
    }

    /* ---------------------------- Working days ----------------------------- */

    public function test_a_student_with_no_slot_is_not_expected_on_a_non_working_day(): void
    {
        $this->workingDays([1, 2, 3, 4, 5]);
        $student = $this->studentOn(null);

        $saturday = '2026-09-19';
        $this->close($saturday);

        // No row at all, not a row saying "closed": everyone knows the office
        // is shut on a Saturday, and a label there would only be clutter.
        $this->assertNull($this->rowFor($student, $saturday));
        $this->assertSame(0, AbsenceFine::absencesIn($student->id, Carbon::parse($saturday)));
    }

    public function test_a_student_with_no_slot_is_expected_on_a_working_day(): void
    {
        $this->workingDays([1, 2, 3, 4, 5]);
        $student = $this->studentOn(null);

        $this->close(self::WEDNESDAY);

        $this->assertSame('absent', $this->rowFor($student, self::WEDNESDAY)->status);
    }

    public function test_a_slot_that_runs_saturday_beats_the_academy_week(): void
    {
        // The academy is Mon–Fri, but this student was admitted into a
        // Saturday group. The slot answers for its own students.
        $this->workingDays([1, 2, 3, 4, 5]);
        $student = $this->studentOn($this->slot([6]));

        $saturday = '2026-09-19';
        $this->close($saturday);

        $this->assertSame('absent', $this->rowFor($student, $saturday)->status);
    }

    /* ------------------------------ Holidays ------------------------------- */

    public function test_a_holiday_beats_a_slot_that_runs_that_weekday(): void
    {
        $this->workingDays([1, 2, 3, 4, 5]);
        $student = $this->studentOn($this->slot([6]));

        $saturday = '2026-09-19';
        $this->holiday($saturday, 'Founders Day');
        $this->close($saturday);

        // Not absent — the academy was shut, and a slot's weekday list says
        // nothing about a day the academy does not open at all.
        $row = $this->rowFor($student, $saturday);
        $this->assertSame(DailyAttendance::HOLIDAY, $row->status);
        $this->assertSame('Founders Day', $row->remarks);
    }

    public function test_a_holiday_on_a_working_weekday_marks_nobody_absent(): void
    {
        $this->workingDays([1, 2, 3, 4, 5]);
        $onSlot = $this->studentOn($this->slot([1, 2, 3, 4, 5]));
        $noSlot = $this->studentOn(null);

        $this->holiday(self::WEDNESDAY, 'Eid ul-Fitr');
        $this->close(self::WEDNESDAY);

        foreach ([$onSlot, $noSlot] as $student) {
            $row = $this->rowFor($student, self::WEDNESDAY);
            $this->assertSame(DailyAttendance::HOLIDAY, $row->status);
            $this->assertSame('Eid ul-Fitr', $row->remarks);
        }

        $this->assertSame(0, DailyAttendance::where('status', 'absent')->count());
    }

    public function test_closing_a_holiday_twice_changes_nothing(): void
    {
        $this->workingDays([1, 2, 3, 4, 5]);
        $student = $this->studentOn(null);
        $this->holiday(self::WEDNESDAY);

        $this->close(self::WEDNESDAY);
        $this->close(self::WEDNESDAY);

        $this->assertSame(1, DailyAttendance::where('user_id', $student->id)->count());
        $this->assertSame(DailyAttendance::HOLIDAY, $this->rowFor($student, self::WEDNESDAY)->status);
    }

    public function test_a_punch_on_a_holiday_is_still_recorded_present(): void
    {
        $this->workingDays([1, 2, 3, 4, 5]);
        $student = $this->studentOn($this->slot([1, 2, 3, 4, 5]), '8001');
        $this->holiday(self::WEDNESDAY);

        [$course] = $this->makeCourse(1);
        $device = BiometricDevice::create([
            'name' => 'Front desk',
            'serial_number' => 'SN-'.uniqid(),
            'course_id' => $course->id,
            'api_key' => BiometricDevice::generateKey(),
            'session_start' => '09:00',
            'late_after_minutes' => 15,
            'is_active' => true,
        ]);
        $this->enroll($student, $course);

        $this->withHeader('X-Device-Key', $device->api_key)
            ->postJson('/api/v1/biometric/punches', ['punches' => [[
                'biometric_id' => '8001',
                'punched_at' => Carbon::parse(self::WEDNESDAY)->setTime(9, 5)->toDateTimeString(),
            ]]])->assertCreated();

        $this->close(self::WEDNESDAY);

        // Attending on a day off is not an absence, but it did happen, and the
        // register says what happened.
        $row = $this->rowFor($student, self::WEDNESDAY);
        $this->assertSame('present', $row->status);
        $this->assertSame('09:05:00', $row->arrived_at);
    }

    /* -------------------------------- Fines -------------------------------- */

    public function test_a_month_with_holidays_costs_the_same_as_one_without_them(): void
    {
        $this->workingDays([1, 2, 3, 4, 5]);
        Setting::updateOrCreate(['key' => 'monthly_absent_allowance'], ['value' => 1, 'group' => 'general']);
        Setting::updateOrCreate(['key' => 'absent_fine_amount'], ['value' => 500, 'group' => 'general']);
        Setting::forgetCached();

        // Mon 14th to Fri 18th September: three of those are holidays.
        $week = ['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18'];
        $holidays = ['2026-09-15', '2026-09-16', '2026-09-17'];

        $withHolidays = $this->studentOn(null, '9001');
        foreach ($holidays as $date) {
            $this->holiday($date, 'Eid ul-Fitr');
        }
        foreach ($week as $date) {
            $this->close($date);
        }

        $month = Carbon::parse('2026-09-01');
        $fineWithHolidays = AbsenceFine::balance($withHolidays->id, $month)['fine_total'];

        // Now the same student's week with those three days simply not in the
        // table: absent from the holidays table, absent from the register.
        Holiday::query()->forceDelete();
        DailyAttendance::query()->delete();

        $withoutHolidays = $this->studentOn(null, '9002');
        foreach (array_diff($week, $holidays) as $date) {
            $this->close($date);
        }

        $fineWithout = AbsenceFine::balance($withoutHolidays->id, $month)['fine_total'];

        // Two absences either way, one free, so one chargeable at 500.
        $this->assertSame(500.0, $fineWithout);
        $this->assertSame($fineWithout, $fineWithHolidays);
        $this->assertSame(2, AbsenceFine::absencesIn($withHolidays->id, $month));
    }

    public function test_a_holiday_row_never_reaches_the_portal_counts(): void
    {
        $this->workingDays([1, 2, 3, 4, 5]);
        $student = $this->studentOn(null);
        $this->holiday(self::WEDNESDAY, 'Eid ul-Fitr');
        $this->close(self::WEDNESDAY);
        $this->close('2026-09-17');

        $this->actingAsStudent($student);

        $summary = $this->getJson('/api/v1/attendance/summary')->assertOk()->json('data');

        // One absence, one holiday: the totals see only the absence, and the
        // rate is 0 rather than 50 — a day off is not half a day attended.
        $this->assertSame(1, $summary['total_sessions']);
        $this->assertSame(1, $summary['absent_count']);
        $this->assertSame(1, $summary['holiday_count']);
        $this->assertSame(0.0, (float) $summary['attendance_rate']);

        // But the list still shows the day, with the holiday's name on it.
        $days = collect($this->getJson('/api/v1/attendance/daily')->assertOk()->json('data'));
        $holiday = $days->firstWhere('date', self::WEDNESDAY);
        $this->assertSame(DailyAttendance::HOLIDAY, $holiday['status']);
        $this->assertSame('Eid ul-Fitr', $holiday['remarks']);
    }

    /* ------------------------------- Calendar ------------------------------ */

    public function test_the_calendar_endpoint_serves_the_working_week_and_holidays(): void
    {
        $this->workingDays([1, 2, 3, 4, 5, 6]);
        $student = $this->studentOn(null);
        $this->holiday('2026-09-25', 'Eid ul-Fitr');
        $this->actingAsStudent($student);

        $this->getJson('/api/v1/attendance/calendar')->assertOk()
            ->assertJsonPath('data.working_days', [1, 2, 3, 4, 5, 6])
            ->assertJsonPath('data.source', 'academy')
            ->assertJsonPath('data.holidays.0.date', '2026-09-25')
            ->assertJsonPath('data.holidays.0.name', 'Eid ul-Fitr');
    }

    public function test_a_holiday_an_admin_adds_reaches_the_portal_without_a_redeploy(): void
    {
        $this->workingDays([1, 2, 3, 4, 5]);
        $student = $this->studentOn(null);

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        // Nothing on the calendar yet.
        $this->actingAsStudent($student);
        $this->getJson('/api/v1/attendance/calendar')->assertOk()->assertJsonCount(0, 'data.holidays');

        // The admin adds one through the panel, as an admin actually would.
        $this->actingAs($admin)
            ->post(route('admin.holidays.store'), ['name' => 'Eid ul-Fitr', 'date' => '2026-09-25'])
            ->assertRedirect();

        $this->actingAsStudent($student);
        $this->getJson('/api/v1/attendance/calendar')->assertOk()
            ->assertJsonCount(1, 'data.holidays')
            ->assertJsonPath('data.holidays.0.name', 'Eid ul-Fitr');

        // And it is a real day off, not just a label: the close records it
        // rather than an absence.
        $this->close('2026-09-25');
        $this->assertSame(DailyAttendance::HOLIDAY, $this->rowFor($student, '2026-09-25')->status);
    }

    public function test_the_calendar_endpoint_reports_a_students_own_slot_days(): void
    {
        $this->workingDays([1, 2, 3, 4, 5]);
        $slot = $this->slot([6, 7]);
        $this->actingAsStudent($this->studentOn($slot));

        $this->getJson('/api/v1/attendance/calendar')->assertOk()
            ->assertJsonPath('data.working_days', [6, 7])
            ->assertJsonPath('data.source', 'slot')
            ->assertJsonPath('data.slot_name', $slot->name);
    }
}
