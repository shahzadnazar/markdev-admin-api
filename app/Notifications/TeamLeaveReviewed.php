<?php

namespace App\Notifications;

use App\Models\TeamLeaveApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * What the admin decided about your leave — in full, in part, or not at all.
 *
 * The academy's LeaveApplicationReviewed over the team's own tables. NOT
 * extended from it: that class reads `$leave->decisions` off a
 * LeaveApplication and points at the student portal's `/attendance`, and a
 * shared parent would have had to be told which table and which portal on every
 * send. The three-way partial wording is the part worth keeping and it is short.
 *
 * A partial approval is neither of the other two, and saying "approved" would
 * hide the days that were not.
 */
class TeamLeaveReviewed extends Notification
{
    use Queueable;

    public function __construct(public TeamLeaveApplication $leave) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $range = $this->leave->from_date->isSameDay($this->leave->to_date)
            ? $this->leave->from_date->format('j M Y')
            : $this->leave->from_date->format('j M').' – '.$this->leave->to_date->format('j M Y');

        $approved = $this->leave->decisions->where('status', 'approved');
        $declined = $this->leave->decisions->where('status', 'declined');

        [$title, $message] = match ($this->leave->status) {
            'approved' => [
                'Leave approved',
                "Your leave for {$range} was approved — those days are excluded from your task clocks.",
            ],
            'partially_approved' => [
                'Leave partly approved',
                sprintf(
                    'Of your leave for %s, %d day(s) were approved (%s) and %d declined.',
                    $range,
                    $approved->count(),
                    $approved->sortBy('date')->map(fn ($day) => $day->date->format('j M'))->implode(', '),
                    $declined->count(),
                ),
            ],
            default => ['Leave declined', "Your leave request for {$range} was declined."],
        };

        // The note is required the moment anything is declined, so a decline
        // always carries its reason here too — the member is owed it on the
        // bell and not only on a screen they have to go and find.
        if ($this->leave->review_note) {
            $message .= ' Note: '.$this->leave->review_note;
        }

        return [
            'title' => $title,
            'message' => $message,
            'action_url' => route('admin.team-leave.mine', absolute: false),
        ];
    }
}
