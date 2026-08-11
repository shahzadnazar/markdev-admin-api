<?php

namespace Tests\Feature\Api;

use App\Models\Announcement;
use App\Models\User;

class AnnouncementNotificationTest extends ApiTestCase
{
    public function test_publishing_an_announcement_rings_the_bell_once(): void
    {
        $user = $this->student();
        [$course] = $this->makeCourse(1);
        $this->enroll($user, $course);
        $outsider = $this->student();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->post(route('admin.announcements.store'), [
                'title' => 'Orientation moved',
                'body' => 'New time is 10am.',
                'course_id' => $course->id,
            ])
            ->assertRedirect();

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(0, $outsider->notifications()->count());
        $this->assertSame(
            'Orientation moved',
            $user->notifications()->first()->data['message'],
        );

        $announcement = Announcement::firstOrFail();
        $this->assertNotNull($announcement->notified_at);

        // Editing the announcement must not notify a second time.
        $this->actingAs($admin)
            ->put(route('admin.announcements.update', $announcement), [
                'title' => 'Orientation moved again',
                'body' => 'New time is 11am.',
                'course_id' => $course->id,
            ])
            ->assertRedirect();

        $this->assertSame(1, $user->notifications()->count());
    }
}
