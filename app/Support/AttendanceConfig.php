<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/** Security + timing knobs of the daily attendance register. */
class AttendanceConfig
{
    /* ------------------------------ Edit PIN ------------------------------ */

    /** Whether an attendance-edit PIN has been configured. */
    public static function hasEditPin(): bool
    {
        return Setting::where('key', 'attendance_edit_pin')->whereNotNull('value')->exists();
    }

    /** Check a submitted PIN against the stored (hashed) one. */
    public static function verifyEditPin(string $pin): bool
    {
        $hash = Setting::where('key', 'attendance_edit_pin')->value('value');

        return $hash !== null && Hash::check($pin, $hash);
    }

    /** Store a new edit PIN (hashed). */
    public static function setEditPin(string $pin): void
    {
        Setting::updateOrCreate(
            ['key' => 'attendance_edit_pin'],
            ['value' => Hash::make($pin), 'group' => 'general'],
        );
    }

    /* ------------------------------- Timing ------------------------------- */

    /** Academy day start, HH:MM (24h). Default 09:00. */
    /* --------------------------- Marking mode ---------------------------- */

    public const MODE_MANUAL = 'manual';
    public const MODE_BIOMETRIC = 'biometric';
    public const MODES = [self::MODE_MANUAL, self::MODE_BIOMETRIC];

    /**
     * How the daily register is filled — only one source may write to it.
     *
     * Letting both in would mean a device punch and an instructor disagreeing
     * about the same day, with no way to tell which is right.
     */
    public static function mode(): string
    {
        $mode = rescue(
            fn () => \App\Models\Setting::cached('attendance_mode'),
            null,
            false,
        );

        return in_array($mode, self::MODES, true) ? $mode : self::MODE_MANUAL;
    }

    /**
     * Switch how the register is filled, telling instructors what changed.
     *
     * Both the Settings page and the daily register call this, so a switch
     * behaves identically wherever it is made: nothing happens when the mode is
     * unchanged, and every instructor is notified when it is.
     *
     * @return bool whether the mode actually changed
     */
    public static function setMode(string $mode, ?\App\Models\User $actor = null): bool
    {
        if (! in_array($mode, self::MODES, true)) {
            return false;
        }

        $before = self::mode();

        \App\Models\Setting::updateOrCreate(
            ['key' => 'attendance_mode'],
            ['value' => $mode, 'group' => 'general'],
        );
        \App\Models\Setting::forgetCached('attendance_mode');

        if ($before === $mode) {
            return false;
        }

        $instructors = \App\Models\User::role('instructor')->get();
        \Illuminate\Support\Facades\Notification::send(
            $instructors,
            new \App\Notifications\AttendanceModeChanged($mode),
        );

        \App\Support\AuditLogger::log('updated', 'settings', null,
            ['attendance_mode' => $before],
            ['attendance_mode' => $mode, 'instructors_notified' => $instructors->count()],
        );

        return true;
    }

    public static function isBiometric(): bool
    {
        return self::mode() === self::MODE_BIOMETRIC;
    }

    public static function isManual(): bool
    {
        return self::mode() === self::MODE_MANUAL;
    }

    public static function dayStart(): string
    {
        $value = (string) (Setting::where('key', 'attendance_day_start')->value('value') ?? '09:00');

        return preg_match('/^\d{2}:\d{2}$/', $value) ? $value : '09:00';
    }

    /** Grace minutes after day start before an arrival counts as late. */
    public static function lateAfterMinutes(): int
    {
        return (int) (Setting::where('key', 'attendance_late_after_minutes')->value('value') ?? 15);
    }

    /** The moment a given day flips from present to late. */
    public static function lateThreshold(Carbon $day): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', static::dayStart()));

        return $day->copy()->setTime($hour, $minute)->addMinutes(static::lateAfterMinutes());
    }

    /** present|late for an arrival moment, per academy timing settings. */
    public static function statusForArrival(Carbon $arrivedAt): string
    {
        return $arrivedAt->greaterThan(static::lateThreshold($arrivedAt)) ? 'late' : 'present';
    }
}
