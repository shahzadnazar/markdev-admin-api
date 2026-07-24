<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Hash;

/** Security knobs of the daily attendance register. */
class AttendanceConfig
{
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
}
