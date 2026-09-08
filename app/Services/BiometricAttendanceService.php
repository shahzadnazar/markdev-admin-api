<?php

namespace App\Services;

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
 *  - The first punch of the day decides the status, judged against the
 *    student's own attendance slot (or the academy day for a student on no
 *    slot) — the same rule every other part of the register uses.
 *  - Later punches on the same day never downgrade an existing record.
 *  - Only the daily register is written. The per-class sheet this also used
 *    to fill was retired: two tables recording the same day disagreed with
 *    each other, and the device's own session_start was a second late rule
 *    competing with the student's slot.
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

        // The register is the academy's one attendance table. A punch fills
        // the day it proves the student was here for, and never overwrites a
        // day that already has an answer — an instructor's mark, an approved
        // leave, or an earlier punch all stand.
        $existing = \App\Models\DailyAttendance::where('user_id', $user->id)
            ->onDate($punch->punched_at)
            ->first();

        $record = $existing;

        // In manual mode instructors own the register, so a punch must not
        // fill the day on their behalf (5a4bf72). The punch is not lost:
        // CloseAttendanceDay settles an unmarked day from the punch itself at
        // end of day, reading biometric_punches rather than any record here.
        if ($existing === null && \App\Support\AttendanceConfig::isBiometric()) {
            // Late is judged against the student's own slot — the time from
            // the slot, the date from this punch — falling back to the
            // academy-wide day start for a student on no slot. This is the
            // system's single late rule; the device's own session_start is no
            // longer a second opinion on the same question.
            $dailyStatus = \App\Support\AttendanceConfig::statusForArrival($punch->punched_at, $user);
            $dayStart = \App\Support\AttendanceConfig::sessionStart($punch->punched_at, $user);
            $minutesLate = (int) $dayStart->diffInMinutes($punch->punched_at, false);

            $record = DB::transaction(fn () => \App\Models\DailyAttendance::create([
                'user_id' => $user->id,
                // What the device knows about the day, carried onto the row
                // the class-attendance sheet used to hold.
                'course_id' => $device->course_id,
                'session_title' => $device->name.($device->location ? ' — '.$device->location : ''),
                'date' => $punch->punched_at->toDateString(),
                'status' => $dailyStatus,
                'arrived_at' => $punch->punched_at->format('H:i:s'),
                'remarks' => $dailyStatus === 'late'
                    ? 'Arrived '.$punch->punched_at->format('g:i A').' — '.max(1, $minutesLate).' min after '.$dayStart->format('g:i A')
                    : 'Punched at '.$punch->punched_at->format('g:i A'),
                'source' => 'biometric',
                'marked_at' => now(),
            ]));
        }

        $punch->fill([
            'status' => BiometricPunch::STATUS_PROCESSED,
            // Null in manual mode on an unmarked day — there is genuinely no
            // row yet. The punches screen hides the badge rather than
            // inventing one.
            'daily_attendance_record_id' => $record?->id,
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

}
