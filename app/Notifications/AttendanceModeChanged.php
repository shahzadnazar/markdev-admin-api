<?php

namespace App\Notifications;

use App\Support\AttendanceConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells instructors which way today's register is being filled.
 *
 * Only one source may write to it, so an instructor who isn't told the mode
 * changed will either mark a register that rejects them, or leave one unmarked
 * expecting devices to cover it.
 */
class AttendanceModeChanged extends Notification
{
    use Queueable;

    public function __construct(public string $mode)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $biometric = $this->mode === AttendanceConfig::MODE_BIOMETRIC;

        return [
            'title' => 'Attendance mode changed',
            'message' => $biometric
                ? "Today's attendance is marked through biometric. Device punches fill the register; manual marking is off."
                : "Today's attendance is marked manually. Please mark your register; device punches no longer fill it.",
            'action_url' => '/admin/attendance/daily',
        ];
    }
}
