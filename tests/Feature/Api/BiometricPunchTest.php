<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceRecord;
use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
use App\Services\BiometricAttendanceService;

class BiometricPunchTest extends ApiTestCase
{
    protected function device(array $attributes = []): BiometricDevice
    {
        [$course] = $this->makeCourse(1);

        return BiometricDevice::create(array_merge([
            'name' => 'Lab Terminal',
            'serial_number' => 'SN-'.uniqid(),
            'course_id' => $course->id,
            'api_key' => BiometricDevice::generateKey(),
            'session_start' => '09:00',
            'late_after_minutes' => 15,
            'is_active' => true,
        ], $attributes));
    }

    protected function punch(BiometricDevice $device, array $punches): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('X-Device-Key', $device->api_key)
            ->postJson('/api/v1/biometric/punches', ['punches' => $punches]);
    }

    public function test_missing_key_is_rejected(): void
    {
        $this->postJson('/api/v1/biometric/punches', ['punches' => []])->assertStatus(401);
    }

    public function test_unknown_or_inactive_device_is_rejected(): void
    {
        $this->withHeader('X-Device-Key', 'mdk_bogus')
            ->postJson('/api/v1/biometric/punches', ['punches' => [['biometric_id' => '1', 'punched_at' => now()->toDateTimeString()]]])
            ->assertStatus(403);

        $device = $this->device(['is_active' => false]);
        $this->punch($device, [['biometric_id' => '1', 'punched_at' => now()->toDateTimeString()]])
            ->assertStatus(403);
    }

    public function test_on_time_punch_marks_present(): void
    {
        $device = $this->device();
        $student = $this->student(['biometric_id' => '1001']);

        $this->punch($device, [[
            'biometric_id' => '1001',
            'punched_at' => now()->setTime(9, 5)->toDateTimeString(),
        ]])->assertStatus(201)->assertJsonPath('data.processed', 1);

        $record = AttendanceRecord::where('user_id', $student->id)->first();
        $this->assertNotNull($record);
        $this->assertSame('present', $record->status);
        $this->assertSame('biometric', $record->source);
        $this->assertSame($device->id, $record->biometric_device_id);
        $this->assertNotNull($device->fresh()->last_seen_at);
    }

    public function test_punch_after_grace_window_marks_late(): void
    {
        $device = $this->device();
        $student = $this->student(['biometric_id' => '1001']);

        $this->punch($device, [[
            'biometric_id' => '1001',
            'punched_at' => now()->setTime(9, 30)->toDateTimeString(),
        ]])->assertStatus(201);

        $this->assertSame('late', AttendanceRecord::where('user_id', $student->id)->value('status'));
    }

    public function test_unknown_biometric_id_is_kept_unmatched(): void
    {
        $device = $this->device();

        $this->punch($device, [[
            'biometric_id' => '4242',
            'punched_at' => now()->toDateTimeString(),
        ]])->assertStatus(201)->assertJsonPath('data.unmatched', 1);

        $this->assertSame(BiometricPunch::STATUS_UNMATCHED, BiometricPunch::first()->status);
        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_duplicate_and_same_day_punches_create_one_record(): void
    {
        $device = $this->device();
        $this->student(['biometric_id' => '1001']);

        $first = ['biometric_id' => '1001', 'punched_at' => now()->setTime(9, 5)->toDateTimeString()];
        $this->punch($device, [$first])->assertStatus(201);

        // Exact replay → duplicate; later same-day punch → linked, no new record.
        $this->punch($device, [
            $first,
            ['biometric_id' => '1001', 'punched_at' => now()->setTime(13, 0)->toDateTimeString()],
        ])->assertStatus(201)->assertJsonPath('data.duplicate', 1);

        $this->assertSame(1, AttendanceRecord::count());
        $this->assertSame('present', AttendanceRecord::first()->status);
    }

    public function test_device_without_course_skips_punches(): void
    {
        $device = $this->device(['course_id' => null]);
        $this->student(['biometric_id' => '1001']);

        $this->punch($device, [[
            'biometric_id' => '1001',
            'punched_at' => now()->toDateTimeString(),
        ]])->assertStatus(201)->assertJsonPath('data.skipped', 1);

        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_unmatched_punches_can_be_reprocessed_after_enrollment(): void
    {
        $device = $this->device();

        $this->punch($device, [[
            'biometric_id' => '1001',
            'punched_at' => now()->setTime(9, 2)->toDateTimeString(),
        ]])->assertJsonPath('data.unmatched', 1);

        $student = $this->student(['biometric_id' => '1001']);

        $count = app(BiometricAttendanceService::class)->reprocessUnmatched($device);

        $this->assertSame(1, $count);
        $this->assertSame('present', AttendanceRecord::where('user_id', $student->id)->value('status'));
    }
}
