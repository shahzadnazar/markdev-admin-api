<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns raw device punches into attendance records.
 *
 * Rules:
 *  - A punch matches a student by users.biometric_id; unknown ids are kept
 *    as `unmatched` so admins can enroll the student and reprocess.
 *  - The first punch of the day decides the status: `present` when it lands
 *    before the device's session_start + late_after_minutes, `late` after.
 *    Devices without a session_start always mark `present`.
 *  - Later punches on the same day never downgrade an existing record.
 *  - Duplicate punches (same device + biometric id + timestamp) are ignored.
 */
class BiometricAttendanceService
{
    /**
     * @param array{biometric_id: string, punched_at: string, direction?: string|null} $data
     */
    public function ingest(BiometricDevice $device, array $data): BiometricPunch
    {
        $punchedAt = Carbon::parse($data['punched_at']);

        $punch = BiometricPunch::firstOrCreate(
            [
                'biometric_device_id' => $device->id,
                'biometric_id' => (string) $data['biometric_id'],
                'punched_at' => $punchedAt,
            ],
            [
                'direction' => $data['direction'] ?? null,
                'status' => BiometricPunch::STATUS_PENDING,
            ],
        );

        // Replayed punch — nothing new to do.
        if (! $punch->wasRecentlyCreated) {
            return $punch;
        }

        return $this->process($punch, $device);
    }

    public function process(BiometricPunch $punch, ?BiometricDevice $device = null): BiometricPunch
    {
        $device ??= $punch->device;

        $user = User::where('biometric_id', $punch->biometric_id)->first();

        if (! $user) {
            $punch->update([
                'status' => BiometricPunch::STATUS_UNMATCHED,
                'note' => 'No student has this biometric id.',
            ]);

            return $punch;
        }

        $punch->user_id = $user->id;

        if (! $device->course_id) {
            $punch->fill([
                'status' => BiometricPunch::STATUS_SKIPPED,
                'note' => 'Device has no course assigned.',
            ])->save();

            return $punch;
        }

        $status = $this->resolveStatus($device, $punch->punched_at);

        // A punch also proves daily presence at the academy - fill the daily
        // register (never overwriting an already-marked day).
        $dailyMarked = \App\Models\DailyAttendance::where('user_id', $user->id)
            ->whereDate('date', $punch->punched_at->toDateString())
            ->exists();

        if (! $dailyMarked) {
            // The daily register judges late against the ACADEMY day start
            // (Settings), independent of any per-device class session.
            $dailyStatus = \App\Support\AttendanceConfig::statusForArrival($punch->punched_at);
            $dayStart = \App\Support\AttendanceConfig::lateThreshold($punch->punched_at)
                ->subMinutes(\App\Support\AttendanceConfig::lateAfterMinutes());
            $minutesLate = (int) $dayStart->diffInMinutes($punch->punched_at, false);

            \App\Models\DailyAttendance::create([
                'user_id' => $user->id,
                'date' => $punch->punched_at->toDateString(),
                'status' => $dailyStatus,
                'arrived_at' => $punch->punched_at->format('H:i:s'),
                'remarks' => $dailyStatus === 'late'
                    ? 'Arrived '.$punch->punched_at->format('g:i A').' — '.max(1, $minutesLate).' min after '.\App\Support\AttendanceConfig::dayStart()
                    : 'Punched at '.$punch->punched_at->format('g:i A'),
                'source' => 'biometric',
                'marked_at' => now(),
            ]);
        }

        $record = DB::transaction(function () use ($punch, $device, $user, $status) {
            $existing = AttendanceRecord::where('user_id', $user->id)
                ->where('course_id', $device->course_id)
                ->whereDate('date', $punch->punched_at->toDateString())
                ->first();

            if ($existing) {
                // Never downgrade a manual or earlier-punch status.
                return $existing;
            }

            return AttendanceRecord::create([
                'user_id' => $user->id,
                'course_id' => $device->course_id,
                'session_title' => $device->name.($device->location ? ' — '.$device->location : ''),
                'date' => $punch->punched_at->toDateString(),
                'status' => $status,
                'notes' => 'Punched at '.$punch->punched_at->format('H:i'),
                'source' => 'biometric',
                'biometric_device_id' => $device->id,
            ]);
        });

        $punch->fill([
            'status' => BiometricPunch::STATUS_PROCESSED,
            'attendance_record_id' => $record->id,
            'note' => null,
        ])->save();

        return $punch;
    }

    /** Reprocess punches that were unmatched (e.g. after enrolling the id). */
    public function reprocessUnmatched(BiometricDevice $device): int
    {
        $count = 0;

        BiometricPunch::where('biometric_device_id', $device->id)
            ->where('status', BiometricPunch::STATUS_UNMATCHED)
            ->orderBy('punched_at')
            ->each(function (BiometricPunch $punch) use ($device, &$count) {
                if ($this->process($punch, $device)->status === BiometricPunch::STATUS_PROCESSED) {
                    $count++;
                }
            });

        return $count;
    }

    protected function resolveStatus(BiometricDevice $device, Carbon $punchedAt): string
    {
        if (! $device->session_start) {
            return 'present';
        }

        $sessionStart = $punchedAt->copy()->setTimeFromTimeString($device->session_start);
        $lateAfter = $sessionStart->copy()->addMinutes($device->late_after_minutes);

        return $punchedAt->lessThanOrEqualTo($lateAfter) ? 'present' : 'late';
    }
}
