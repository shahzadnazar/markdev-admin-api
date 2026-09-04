<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceSlot;
use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
use App\Models\DailyAttendance;
use App\Models\Setting;
use App\Models\User;
use App\Support\AttendanceConfig;
use Illuminate\Support\Carbon;

/**
 * What a punch actually does to the daily register.
 *
 * Written as a diagnosis rather than a regression guard: the register decides
 * who is absent, and since absences are billable an unreliable punch costs a
 * student money.
 */
class BiometricRegisterPathTest extends ApiTestCase
{
    protected function device(array $overrides = []): BiometricDevice
    {
        [$course] = $this->makeCourse(1);

        return BiometricDevice::create(array_merge([
            'name' => 'Front desk',
            'serial_number' => 'SN-'.uniqid(),
            'course_id' => $course->id,
            'api_key' => BiometricDevice::generateKey(),
            'session_start' => '09:00',
            'late_after_minutes' => 15,
            'is_active' => true,
        ], $overrides));
    }

    protected function studentOn(?AttendanceSlot $slot, string $biometricId): User
    {
        // biometric_id lives on the user; the slot on their profile.
        $student = User::factory()->create(['biometric_id' => $biometricId]);
        $student->assignRole('student');
        $student->studentProfile()->create([
            'reg_no' => 'MD-'.$biometricId,
            'attendance_slot_id' => $slot?->id,
        ]);

        return $student->fresh();
    }

    protected function slot(string $start, int $grace, ?array $days = null): AttendanceSlot
    {
        return AttendanceSlot::create([
            'name' => 'Slot '.$start.uniqid(),
            'start_time' => $start,
            'end_time' => '23:30:00',
            'days' => $days ?? array_keys(AttendanceSlot::DAYS),
            'late_after_minutes' => $grace,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    /** Through the real endpoint, so the whole path is exercised. */
    protected function punchAt(BiometricDevice $device, User $student, Carbon $at): void
    {
        $this->withHeader('X-Device-Key', $device->api_key)
            ->postJson('/api/v1/biometric/punches', ['punches' => [[
                'biometric_id' => $student->biometric_id,
                'punched_at' => $at->toDateTimeString(),
            ]]])
            ->assertCreated();
    }

    protected function registerFor(User $student, Carbon $day): ?DailyAttendance
    {
        return DailyAttendance::where('user_id', $student->id)->onDate($day)->first();
    }

    protected function useBiometricMode(): void
    {
        Setting::updateOrCreate(['key' => 'attendance_mode'], ['value' => 'biometric', 'group' => 'general']);
        Setting::forgetCached();
    }

    /* ------------------------- Biometric mode: the design ------------------- */

    public function test_in_biometric_mode_a_punch_before_the_slot_cutoff_is_present(): void
    {
        $this->useBiometricMode();
        $device = $this->device();
        $slot = $this->slot('09:00:00', 15);
        $student = $this->studentOn($slot, '5001');
        $this->enroll($student, $device->course);

        $at = today()->setTime(9, 5);
        $this->punchAt($device, $student, $at);

        $row = $this->registerFor($student, $at);
        $this->assertNotNull($row, 'A punch in biometric mode must reach the register.');
        $this->assertSame('present', $row->status);
        $this->assertSame('biometric', $row->source);
        $this->assertSame('09:05:00', $row->arrived_at);
    }

    public function test_in_biometric_mode_a_punch_past_the_slot_grace_is_late(): void
    {
        $this->useBiometricMode();
        $device = $this->device();
        $slot = $this->slot('09:00:00', 15);
        $student = $this->studentOn($slot, '5002');
        $this->enroll($student, $device->course);

        $at = today()->setTime(9, 40);
        $this->punchAt($device, $student, $at);

        $row = $this->registerFor($student, $at);
        $this->assertSame('late', $row->status);
        $this->assertSame('09:40:00', $row->arrived_at);
        $this->assertStringContainsString('40 min after 9:00 AM', $row->remarks);
    }

    public function test_a_student_with_no_slot_falls_back_to_the_academy_day(): void
    {
        $this->useBiometricMode();
        Setting::updateOrCreate(['key' => 'attendance_day_start'], ['value' => '08:00', 'group' => 'general']);
        Setting::updateOrCreate(['key' => 'attendance_late_after_minutes'], ['value' => 10, 'group' => 'general']);
        Setting::forgetCached();

        $device = $this->device();
        $student = $this->studentOn(null, '5003');
        $this->enroll($student, $device->course);

        $at = today()->setTime(8, 30);
        $this->punchAt($device, $student, $at);

        // 08:30 is past 08:00 + 10, so late against the academy day.
        $this->assertSame('late', $this->registerFor($student, $at)->status);
    }

    public function test_a_punch_on_a_day_the_slot_does_not_run_is_judged_by_the_academy_day(): void
    {
        $this->useBiometricMode();
        Setting::updateOrCreate(['key' => 'attendance_day_start'], ['value' => '09:00', 'group' => 'general']);
        Setting::updateOrCreate(['key' => 'attendance_late_after_minutes'], ['value' => 15, 'group' => 'general']);
        Setting::forgetCached();

        $device = $this->device();
        // A slot that runs on every day except today.
        $otherDays = array_values(array_diff(array_keys(AttendanceSlot::DAYS), [today()->dayOfWeekIso]));
        $student = $this->studentOn($this->slot('14:00:00', 15, $otherDays), '5004');
        $this->enroll($student, $device->course);

        $at = today()->setTime(9, 5);
        $this->punchAt($device, $student, $at);

        $row = $this->registerFor($student, $at);
        $this->assertNotNull($row, 'A punch on a non-slot day still records attendance.');
        // Judged against the academy day, not the 2pm slot that does not run.
        $this->assertSame('present', $row->status);
    }

    public function test_an_unmatched_biometric_id_writes_no_register_row(): void
    {
        $this->useBiometricMode();
        $device = $this->device();

        $this->withHeader('X-Device-Key', $device->api_key)
            ->postJson('/api/v1/biometric/punches', ['punches' => [[
                'biometric_id' => 'nobody-has-this',
                'punched_at' => today()->setTime(9, 5)->toDateTimeString(),
            ]]])
            ->assertCreated()
            ->assertJsonPath('data.unmatched', 1);

        $this->assertSame(BiometricPunch::STATUS_UNMATCHED, BiometricPunch::first()->status);
        $this->assertSame(0, DailyAttendance::count());
    }

    public function test_a_day_with_no_punch_closes_absent(): void
    {
        $this->useBiometricMode();
        $student = $this->studentOn($this->slot('09:00:00', 15), '5005');

        $this->artisan('attendance:close-day')->assertSuccessful();

        $this->assertSame('absent', $this->registerFor($student, today())->status);
    }

    public function test_a_punched_day_is_not_overwritten_by_the_close(): void
    {
        $this->useBiometricMode();
        $device = $this->device();
        $student = $this->studentOn($this->slot('09:00:00', 15), '5006');
        $this->enroll($student, $device->course);

        $this->punchAt($device, $student, today()->setTime(9, 5));
        $this->artisan('attendance:close-day')->assertSuccessful();

        $this->assertSame('present', $this->registerFor($student, today())->status);
    }

    /* -------------------------- Manual mode: the hazard --------------------- */

    public function test_in_manual_mode_a_punch_does_not_reach_the_register(): void
    {
        // Default mode. The punch records the class session, and the register
        // is left to the instructor by design (5a4bf72).
        $this->assertTrue(AttendanceConfig::isManual());

        $device = $this->device();
        $student = $this->studentOn($this->slot('09:00:00', 15), '5007');
        $this->enroll($student, $device->course);

        $at = today()->setTime(9, 5);
        $this->punchAt($device, $student, $at);

        $this->assertNull($this->registerFor($student, $at));
        // The class record is still written, so the punch is not lost.
        $this->assertSame('present', \App\Models\AttendanceRecord::where('user_id', $student->id)->value('status'));
    }

    public function test_in_manual_mode_a_punched_day_closes_from_the_punch(): void
    {
        $device = $this->device();
        $student = $this->studentOn($this->slot('09:00:00', 15), '5008');
        $this->enroll($student, $device->course);

        $this->punchAt($device, $student, today()->setTime(9, 5));
        $this->artisan('attendance:close-day')->assertSuccessful();

        // The money question: they scanned in, so the close must not turn the
        // day into a chargeable absence just because nobody marked it.
        $row = $this->registerFor($student, today());
        $this->assertSame('present', $row->status);
        $this->assertSame('09:05:00', $row->arrived_at);
        $this->assertStringContainsString('biometric punch', $row->remarks);
    }

    public function test_a_pending_day_closes_from_that_student_s_own_punch(): void
    {
        $device = $this->device();
        $early = $this->slot('08:00:00', 15);
        $mid = $this->slot('09:00:00', 15);

        // Three students, chosen so the bulk update cannot get away with
        // reusing one student's verdict: the last two punch at the same
        // minute but sit on different slots, so the same arrival time is
        // late for one and on time for the other.
        $lateEarly = $this->studentOn($early, '5021');   // 09:40 on an 08:00 slot
        $onTime = $this->studentOn($mid, '5022');        // 09:05 on a 09:00 slot
        $lateAtNine = $this->studentOn($early, '5023');  // 09:05 on an 08:00 slot

        foreach ([$lateEarly, $onTime, $lateAtNine] as $student) {
            $this->enroll($student, $device->course);
            // A row an instructor opened but never decided. This is the
            // branch that settles in bulk rather than per student.
            DailyAttendance::create([
                'user_id' => $student->id,
                'date' => today()->toDateString(),
                'status' => DailyAttendance::PENDING,
                'marked_at' => now(),
            ]);
        }

        $this->punchAt($device, $lateEarly, today()->setTime(9, 40));
        $this->punchAt($device, $onTime, today()->setTime(9, 5));
        $this->punchAt($device, $lateAtNine, today()->setTime(9, 5));

        $this->artisan('attendance:close-day')->assertSuccessful();

        $this->assertSame('late', $this->registerFor($lateEarly, today())->status);
        $this->assertSame('09:40:00', $this->registerFor($lateEarly, today())->arrived_at);

        $this->assertSame('present', $this->registerFor($onTime, today())->status);
        $this->assertSame('09:05:00', $this->registerFor($onTime, today())->arrived_at);

        // The one the bulk update is most likely to get wrong: same arrival
        // time as the student above, different slot, different verdict.
        $this->assertSame('late', $this->registerFor($lateAtNine, today())->status);
        $this->assertSame('09:05:00', $this->registerFor($lateAtNine, today())->arrived_at);
    }

    public function test_a_punch_past_the_grace_closes_late_not_present(): void
    {
        $device = $this->device();
        $student = $this->studentOn($this->slot('09:00:00', 15), '5009');
        $this->enroll($student, $device->course);

        $this->punchAt($device, $student, today()->setTime(9, 40));
        $this->artisan('attendance:close-day')->assertSuccessful();

        $this->assertSame('late', $this->registerFor($student, today())->status);
    }

    public function test_an_approved_leave_day_beats_a_punch(): void
    {
        $device = $this->device();
        $student = $this->studentOn($this->slot('09:00:00', 15), '5010');
        $this->enroll($student, $device->course);

        $leave = \App\Models\LeaveApplication::create([
            'user_id' => $student->id,
            'from_date' => today()->toDateString(),
            'to_date' => today()->toDateString(),
            'reason' => 'Approved, but came in anyway',
        ]);
        $leave->openDecisions();
        $leave->recordDecisions([today()->toDateString()]);

        $this->punchAt($device, $student, today()->setTime(9, 5));
        $this->artisan('attendance:close-day')->assertSuccessful();

        // Leave is a decision somebody made; a punch is only evidence. An
        // instructor can correct this if the student really did attend.
        $this->assertSame('leave', $this->registerFor($student, today())->status);
    }

    public function test_an_unmatched_punch_still_closes_absent(): void
    {
        // The punch never resolved to a student, so there is no evidence
        // attached to anyone — the day closes as it would have anyway.
        $device = $this->device();
        $student = $this->studentOn($this->slot('09:00:00', 15), '5011');

        $this->withHeader('X-Device-Key', $device->api_key)
            ->postJson('/api/v1/biometric/punches', ['punches' => [[
                'biometric_id' => 'nobody-has-this',
                'punched_at' => today()->setTime(9, 5)->toDateTimeString(),
            ]]])->assertCreated();

        $this->artisan('attendance:close-day')->assertSuccessful();

        $this->assertSame('absent', $this->registerFor($student, today())->status);
    }

    public function test_closing_twice_still_changes_nothing(): void
    {
        $device = $this->device();
        $student = $this->studentOn($this->slot('09:00:00', 15), '5012');
        $this->enroll($student, $device->course);

        $this->punchAt($device, $student, today()->setTime(9, 5));
        $this->artisan('attendance:close-day')->assertSuccessful();
        $first = $this->registerFor($student, today());

        $this->artisan('attendance:close-day')->assertSuccessful();
        $second = $this->registerFor($student, today());

        $this->assertSame($first->status, $second->status);
        $this->assertEquals($first->updated_at, $second->updated_at);
    }
}
