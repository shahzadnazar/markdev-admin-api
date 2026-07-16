<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

class AuthTest extends ApiTestCase
{
    public function test_login_succeeds_with_valid_credentials(): void
    {
        $user = $this->student(['email' => 'student@example.test']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'student@example.test',
            'password' => 'password',
            'remember' => true,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'roles', 'permissions']]])
            ->assertJsonPath('data.user.email', 'student@example.test')
            ->assertJsonPath('data.user.roles.0', 'student');

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'portal',
        ]);

        // The Login event must reach the audit trail.
        $this->assertTrue(AuditLog::where('action', 'login')->where('user_id', $user->id)->exists());
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $this->student(['email' => 'student@example.test']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'student@example.test',
            'password' => 'wrong-password',
            'remember' => false,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');

        $this->assertTrue(AuditLog::where('action', 'failed_login')->exists());
    }

    public function test_login_fails_for_inactive_user(): void
    {
        $this->student(['email' => 'inactive@example.test', 'is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@example.test',
            'password' => 'password',
            'remember' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = $this->actingAsStudent();

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.roles.0', 'student')
            ->assertJsonStructure(['data' => ['permissions', 'avatar_url', 'phone', 'bio', 'headline', 'created_at']]);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = $this->student(['email' => 'student@example.test']);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'student@example.test',
            'password' => 'password',
            'remember' => false,
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertTrue(AuditLog::where('action', 'logout')->where('user_id', $user->id)->exists());
    }

    public function test_password_update_requires_the_current_password(): void
    {
        $this->actingAsStudent();

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'nope',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();
    }

    public function test_profile_can_be_updated(): void
    {
        $user = $this->actingAsStudent();

        $this->putJson('/api/v1/auth/profile', [
            'name' => 'Renamed Student',
            'headline' => 'Lifelong learner',
            'phone' => '+123456789',
        ])->assertOk()->assertJsonPath('data.name', 'Renamed Student');

        $this->assertSame('Lifelong learner', $user->fresh()->headline);
    }

    public function test_cross_user_records_are_hidden(): void
    {
        $other = $this->student();
        Sanctum::actingAs($this->student());

        $notification = $other->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\AssignmentGraded',
            'data' => ['title' => 'x', 'message' => 'y', 'action_url' => null],
        ]);

        $this->deleteJson("/api/v1/notifications/{$notification->id}")->assertNotFound();
    }
}
