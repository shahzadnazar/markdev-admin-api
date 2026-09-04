<?php

namespace App\Notifications;

use App\Models\LeaveApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Tells the student what the reviewer decided — in full, in part, or not at all. */
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
        $range = $this->leave->from_date->isSameDay($this->leave->to_date)
            ? $this->leave->from_date->format('M j, Y')
            : $this->leave->from_date->format('M j').' – '.$this->leave->to_date->format('M j, Y');

        // A part-approved range is neither of the other two: saying "approved"
        // would hide the days that were not, and "rejected" the days that were.
        $approvedDays = $this->leave->decisions->where('status', 'approved');
        $declinedDays = $this->leave->decisions->where('status', 'declined');

        [$title, $message] = match ($this->leave->status) {
            'approved' => [
                'Leave approved',
                "Your leave for {$range} was approved — those days count as present in your attendance.",
            ],
            'partially_approved' => [
                'Leave partly approved',
                sprintf(
                    'Of your leave for %s, %d day(s) were approved (%s) and %d declined.',
                    $range,
                    $approvedDays->count(),
                    $approvedDays->sortBy('date')->map(fn ($day) => $day->date->format('M j'))->implode(', '),
                    $declinedDays->count(),
                ),
            ],
            default => ['Leave rejected', "Your leave request for {$range} was rejected."],
        };

        if ($this->leave->review_note) {
            $message .= ' Note: '.$this->leave->review_note;
        }

        return [
            'title' => $title,
            'message' => $message,
            'action_url' => '/attendance',
        ];
    }
}
