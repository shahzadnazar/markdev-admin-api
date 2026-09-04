<?php

namespace Tests\Feature\Api;

use App\Models\BiometricDevice;
use App\Models\DailyAttendance;
use App\Models\User;

/**
 * The audit that answers "was anyone already billed for a day they attended?".
 */
class AuditPunchedAbsencesTest extends ApiTestCase
{
    protected function absentStudent(string $biometricId, string $date): User
    {
        $student = User::factory()->create(['biometric_id' => $biometricId]);
        $student->assignRole('student');

        DailyAttendance::create([
            'user_id' => $student->id,
            'date' => $date,
            'status' => 'absent',
            'source' => 'auto',
            'marked_at' => now(),
        ]);

        return $student;
    }

    protected function device(): BiometricDevice
    {
        [$course] = $this->makeCourse(1);

        return BiometricDevice::create([
            'name' => 'Front desk',
            'serial_number' => 'SN-'.uniqid(),
            'course_id' => $course->id,
            'api_key' => BiometricDevice::generateKey(),
            'session_start' => '09:00',
            'late_after_minutes' => 15,
            'is_active' => true,
        ]);
    }

    protected function punch(BiometricDevice $device, User $student, string $at): void
    {
        $this->withHeader('X-Device-Key', $device->api_key)
            ->postJson('/api/v1/biometric/punches', ['punches' => [[
                'biometric_id' => $student->biometric_id,
                'punched_at' => $at,
            ]]])->assertCreated();
    }

    public function test_it_reports_an_absence_contradicted_by_a_punch(): void
    {
        $device = $this->device();
        $day = today()->subDays(3);

        $wronged = $this->absentStudent('9001', $day->toDateString());
        $this->enroll($wronged, $device->course);
        $this->punch($device, $wronged, $day->copy()->setTime(9, 6)->toDateTimeString());

        // Absent with no punch — a real absence, and it must not be listed.
        $genuine = $this->absentStudent('9002', $day->toDateString());
        $this->enroll($genuine, $device->course);

        // Punched, but on a different day than the absence.
        $otherDay = $this->absentStudent('9003', $day->toDateString());
        $this->enroll($otherDay, $device->course);
        $this->punch($device, $otherDay, $day->copy()->subDay()->setTime(9, 6)->toDateTimeString());

        $this->artisan('attendance:audit-punched-absences')
            ->expectsOutputToContain($wronged->email)
            ->doesntExpectOutputToContain($genuine->email)
            ->doesntExpectOutputToContain($otherDay->email)
            ->assertSuccessful();
    }

    public function test_it_writes_nothing(): void
    {
        $device = $this->device();
        $day = today()->subDay();
        $student = $this->absentStudent('9004', $day->toDateString());
        $this->enroll($student, $device->course);
        $this->punch($device, $student, $day->copy()->setTime(9, 6)->toDateTimeString());

        $this->artisan('attendance:audit-punched-absences')->assertSuccessful();

        $this->assertSame('absent', DailyAttendance::where('user_id', $student->id)->value('status'));
    }

    public function test_a_clean_window_says_so(): void
    {
        $this->artisan('attendance:audit-punched-absences')
            ->expectsOutputToContain('No absences in that window.')
            ->assertSuccessful();
    }
}
