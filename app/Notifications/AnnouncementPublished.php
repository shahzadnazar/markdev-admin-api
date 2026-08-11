<?php

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Rings the student's portal bell when an announcement goes live. */
class AnnouncementPublished extends Notification
{
    use Queueable;

    public function __construct(public Announcement $announcement)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $scope = $this->announcement->course?->title ?? 'MarkDev';

        return [
            'title' => "New announcement — {$scope}",
            'message' => $this->announcement->title,
            'action_url' => '/announcements',
        ];
    }
}
