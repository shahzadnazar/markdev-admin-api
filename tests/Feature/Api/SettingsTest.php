<?php

namespace Tests\Feature\Api;

class SettingsTest extends ApiTestCase
{
    public function test_defaults_are_returned_when_no_row_exists(): void
    {
        $this->actingAsStudent();

        $this->getJson('/api/v1/settings')->assertOk()->assertJson([
            'data' => [
                'timezone' => 'UTC',
                'language' => 'en',
                'notifications' => [
                    'email_announcements' => true,
                    'email_assignment_graded' => true,
                    'email_due_reminders' => true,
                    'email_new_content' => false,
                    'push_announcements' => true,
                    'push_due_reminders' => true,
                ],
            ],
        ]);
    }

    public function test_partial_update_merges_notifications(): void
    {
        $user = $this->actingAsStudent();

        $this->putJson('/api/v1/settings', [
            'timezone' => 'Europe/Berlin',
            'notifications' => ['push_announcements' => false],
        ])->assertOk()
            ->assertJsonPath('data.timezone', 'Europe/Berlin')
            ->assertJsonPath('data.language', 'en')
            ->assertJsonPath('data.notifications.push_announcements', false)
            ->assertJsonPath('data.notifications.email_announcements', true);

        // A later partial update keeps earlier choices.
        $this->putJson('/api/v1/settings', ['language' => 'de'])->assertOk()
            ->assertJsonPath('data.timezone', 'Europe/Berlin')
            ->assertJsonPath('data.language', 'de')
            ->assertJsonPath('data.notifications.push_announcements', false);

        $this->assertDatabaseHas('user_settings', [
            'user_id' => $user->id,
            'timezone' => 'Europe/Berlin',
            'language' => 'de',
        ]);
    }

    public function test_timezone_is_validated(): void
    {
        $this->actingAsStudent();

        $this->putJson('/api/v1/settings', ['timezone' => 'Mars/Olympus_Mons'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('timezone');
    }
}
