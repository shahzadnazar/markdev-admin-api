<?php

namespace App\Notifications;

use App\Models\LeaveApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Tells the student their leave application was approved or rejected. */
class LeaveApplicationReviewed extends Notification
{
    use Queueable;

    public function __construct(public LeaveApplication $leave)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $approved = $this->leave->status === 'approved';

        $range = $this->leave->from_date->isSameDay($this->leave->to_date)
            ? $this->leave->from_date->format('M j, Y')
            : $this->leave->from_date->format('M j').' – '.$this->leave->to_date->format('M j, Y');

        $message = $approved
            ? "Your leave for {$range} was approved — those days count as present in your attendance."
            : "Your leave request for {$range} was rejected.";

        if ($this->leave->review_note) {
            $message .= ' Note: '.$this->leave->review_note;
        }

        return [
            'title' => $approved ? 'Leave approved' : 'Leave rejected',
            'message' => $message,
            'action_url' => '/attendance',
        ];
    }
}
