<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Str;

class NotificationTest extends ApiTestCase
{
    protected function notify(User $user, bool $read = false): string
    {
        $id = (string) Str::uuid();

        $user->notifications()->create([
            'id' => $id,
            'type' => 'App\\Notifications\\AssignmentGraded',
            'data' => ['title' => 'Assignment graded', 'message' => 'You scored 90.', 'action_url' => '/assignments/1'],
            'read_at' => $read ? now() : null,
        ]);

        return $id;
    }

    public function test_list_counts_and_unread_filter(): void
    {
        $user = $this->actingAsStudent();
        $this->notify($user);
        $this->notify($user, read: true);

        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'assignment_graded')
            ->assertJsonPath('data.0.data.title', 'Assignment graded')
            ->assertJsonPath('data.0.data.action_url', '/assignments/1');

        $this->getJson('/api/v1/notifications?unread=1')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/notifications/counts')->assertOk()
            ->assertJsonPath('data.unread', 1);
    }

    public function test_read_read_all_and_delete(): void
    {
        $user = $this->actingAsStudent();
        $first = $this->notify($user);
        $this->notify($user);
        $this->notify($user);

        $this->patchJson("/api/v1/notifications/{$first}/read")->assertOk();
        $this->assertSame(2, $user->unreadNotifications()->count());

        $this->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->assertSame(0, $user->unreadNotifications()->count());

        $this->deleteJson("/api/v1/notifications/{$first}")->assertNoContent();
        $this->assertSame(2, $user->notifications()->count());
    }
}
