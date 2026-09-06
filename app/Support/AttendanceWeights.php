<?php

namespace App\Support;

use App\Models\DailyAttendance;
use App\Models\Setting;

/**
 * What one attended day is worth, out of 100.
 *
 * These were a PHP constant until an academy wanted to say a late arrival is
 * worth 80 rather than 70. They are settings now, and DailyAttendance::WEIGHTS
 * stays as the default each one falls back to — so a database that has never
 * been written to, or one that cannot be reached at all, produces exactly the
 * percentages it always did.
 *
 * Read through Setting::cached, which loads the whole table once per request
 * and rescues a missing table rather than taking the panel down.
 */
class AttendanceWeights
{
    /** Setting key for one status's weight. */
    public static function keyFor(string $status): string
    {
        return 'attendance_weight_'.$status;
    }

    /** One status's weight, 0–100. */
    public static function for(string $status): int
    {
        $default = DailyAttendance::WEIGHTS[$status] ?? 0;
        $stored = Setting::cached(static::keyFor($status));

        // A stored value outside 0–100 is meaningless as a percentage; the
        // Settings form refuses it, and a hand-edited row falls back rather
        // than skewing every rate in the system.
        if ($stored === null || ! is_numeric($stored)) {
            return $default;
        }

        $value = (int) $stored;

        return $value >= 0 && $value <= 100 ? $value : $default;
    }

    /**
     * Every weight, in the order the register presents them.
     *
     * @return array<string, int>
     */
    public static function all(): array
    {
        $weights = [];

        foreach (DailyAttendance::WEIGHTS as $status => $default) {
            $weights[$status] = static::for($status);
        }

        return $weights;
    }
}
