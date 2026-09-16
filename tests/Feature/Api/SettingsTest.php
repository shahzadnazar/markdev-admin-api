<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Schema;

/**
 * The student's settings: notification preferences, and nothing else.
 *
 * There was a `language` field until 2026_09_16_180000. It saved English or
 * Urdu and nothing read it — the portal has no i18n layer at all — so a
 * student who picked Urdu saw English and had every reason to think the app
 * was broken. The tests below that used to assert it round-tripped now assert
 * it is gone, because a value that persists and means nothing is the bug.
 */
class SettingsTest extends ApiTestCase
{
    public function test_defaults_are_returned_when_no_row_exists(): void
    {
        $this->actingAsStudent();

        $this->getJson('/api/v1/settings')->assertOk()->assertJson([
            'data' => [
                'notifications' => [
                    'email_announcements' => true,
                    'email_assignment_graded' => true,
                    'email_due_reminders' => true,
                    'email_new_content' => false,
                    'push_announcements' => true,
                    'push_due_reminders' => true,
                ],
            ],
        ])->assertJsonMissingPath('data.language');
    }

    public function test_partial_update_merges_notifications(): void
    {
        $user = $this->actingAsStudent();

        $this->putJson('/api/v1/settings', [
            'notifications' => ['push_announcements' => false],
        ])->assertOk()
            ->assertJsonPath('data.notifications.push_announcements', false)
            ->assertJsonPath('data.notifications.email_announcements', true);

        // A later partial update keeps earlier choices.
        $this->putJson('/api/v1/settings', [
            'notifications' => ['email_due_reminders' => false],
        ])->assertOk()
            ->assertJsonPath('data.notifications.email_due_reminders', false)
            ->assertJsonPath('data.notifications.push_announcements', false);

        $this->assertDatabaseHas('user_settings', ['user_id' => $user->id]);
    }

    /**
     * The toggles persist across a fresh read, not just in the response body.
     *
     * A response echoing what it was sent proves nothing about what was
     * stored; this re-reads the endpoint as a separate request.
     */
    public function test_the_notification_toggles_survive_a_reload(): void
    {
        $this->actingAsStudent();

        $this->putJson('/api/v1/settings', [
            'notifications' => [
                'push_announcements' => false,
                'email_new_content' => true,
            ],
        ])->assertOk();

        $this->getJson('/api/v1/settings')->assertOk()
            ->assertJsonPath('data.notifications.push_announcements', false)
            ->assertJsonPath('data.notifications.email_new_content', true)
            // Untouched ones keep their defaults.
            ->assertJsonPath('data.notifications.email_announcements', true);
    }

    public function test_timezone_is_not_a_stored_setting(): void
    {
        $user = $this->actingAsStudent();

        // Sending one is accepted and ignored rather than 422-ing, so an older
        // build of the portal keeps working; it just has nowhere to land.
        $this->putJson('/api/v1/settings', ['timezone' => 'Europe/Berlin'])
            ->assertOk()
            ->assertJsonMissingPath('data.timezone');

        $this->assertDatabaseHas('user_settings', ['user_id' => $user->id]);
    }

    /* ------------------------------ language, gone --------------------------- */

    public function test_the_response_carries_no_language(): void
    {
        $this->actingAsStudent();

        $this->getJson('/api/v1/settings')->assertOk()->assertJsonMissingPath('data.language');
    }

    /**
     * An older portal build still posting `language` is ignored, not refused.
     *
     * Same courtesy as `timezone` above: a deployed client that has not caught
     * up should keep saving its notification toggles rather than 422 on a
     * field the server has retired.
     */
    public function test_a_stale_client_posting_language_is_ignored_not_refused(): void
    {
        $user = $this->actingAsStudent();

        $this->putJson('/api/v1/settings', [
            'language' => 'ur',
            'notifications' => ['push_announcements' => false],
        ])->assertOk()
            ->assertJsonMissingPath('data.language')
            ->assertJsonPath('data.notifications.push_announcements', false);

        $this->assertDatabaseHas('user_settings', ['user_id' => $user->id]);
    }

    public function test_no_code_path_references_the_language_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('user_settings', 'language'),
            'user_settings.language is dropped by 2026_09_16_180000',
        );

        $offences = [];

        foreach ([app_path(), base_path('database/seeders')] as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                // Comments stripped: this file, the migration and the model all
                // explain the removal in prose, and a guard that fires on its
                // own explanation is a guard people delete.
                $code = preg_replace(
                    ['#/\*[\s\S]*?\*/#', '#^\s*//.*$#m'],
                    ' ',
                    file_get_contents($file->getPathname()),
                );

                // The setting, not Laravel's own localisation: app()->getLocale()
                // and config('app.locale') are a different thing and stay.
                if (preg_match("/['\"]language['\"]|->language\b/", $code)) {
                    $offences[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        sort($offences);

        $this->assertSame(
            [],
            $offences,
            "user_settings.language is retired — these still reference it:\n  ".implode("\n  ", $offences),
        );
    }
}
