<?php

namespace Tests\Feature\Admin;

use App\Models\RuleTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Support\AttendanceWeights;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** The admin side of the rules page: the weights, and the wording. */
class RuleTemplateAdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Notification::fake();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    /** @return array<string, mixed> */
    protected function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'site_name' => 'MarkDev',
            'registration_fee' => 2000,
            'defaulter_fine_per_day' => 100,
            'billing_grace_days' => 5,
            'billing_activation_days' => 5,
            'attendance_day_start_hour' => 9,
            'attendance_day_start_minute' => 0,
            'attendance_day_start_meridiem' => 'AM',
            'attendance_late_after_minutes' => 15,
            'academy_working_days' => [1, 2, 3, 4, 5],
            'holiday_announce_days_before' => 1,
            'attendance_weight_present' => 100,
            'attendance_weight_late' => 70,
            'attendance_weight_leave' => 50,
            'attendance_weight_excused' => 50,
            'attendance_weight_absent' => 0,
            'monthly_leave_allowance' => 2,
            'monthly_absent_allowance' => 2,
            'absent_fine_amount' => 500,
            'attendance_mode' => 'manual',
            'quiz_default_attempts' => 1,
            'quiz_seconds_per_question' => 30,
        ], $overrides);
    }

    public function test_the_weights_are_saved_from_the_settings_form(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), $this->settingsPayload(['attendance_weight_late' => 80]))
            ->assertRedirect();

        Setting::forgetCached();
        $this->assertSame(80, AttendanceWeights::for('late'));
        // The others are untouched by that save.
        $this->assertSame(100, AttendanceWeights::for('present'));
    }

    public function test_a_weight_above_one_hundred_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), $this->settingsPayload(['attendance_weight_present' => 120]))
            ->assertSessionHasErrors('attendance_weight_present');

        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), $this->settingsPayload(['attendance_weight_absent' => -1]))
            ->assertSessionHasErrors('attendance_weight_absent');
    }

    public function test_the_rules_screen_renders_with_a_preview_and_a_last_updated_date(): void
    {
        $this->actingAs($this->admin)->get(route('admin.rules.index'))
            ->assertOk()
            ->assertSee('Rules &amp; Regulations', false)
            // Escaped once, not twice — the ampersand must not reach the page
            // as a literal "&amp;".
            ->assertDontSee('Rules &amp;amp; Regulations', false)
            ->assertSee('leave.allowance')
            // The preview shows the finished sentence, not the template.
            ->assertSee('up to 2 leave days per month')
            ->assertSee('Available placeholders')
            ->assertSee('Last updated');
    }

    public function test_an_admin_can_reword_a_rule(): void
    {
        $rule = RuleTemplate::where('key', 'leave.allowance')->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('admin.rules.update', $rule), ['body' => 'You get {monthly_leave_allowance} leave days each month.'])
            ->assertRedirect();

        $this->assertSame('You get {monthly_leave_allowance} leave days each month.', $rule->fresh()->body);
        $this->assertTrue($rule->fresh()->isEdited());
    }

    public function test_a_typo_in_a_placeholder_is_refused_rather_than_shipped(): void
    {
        $rule = RuleTemplate::where('key', 'leave.allowance')->firstOrFail();
        $before = $rule->body;

        $this->actingAs($this->admin)
            ->from(route('admin.rules.index'))
            ->put(route('admin.rules.update', $rule), [
                'body' => 'You get {monthly_leave_alowance} days.',
                'rule_id' => $rule->id,
            ])
            ->assertSessionHasErrors('body');

        // A student would otherwise read a literal {typo} mid-sentence.
        $this->assertSame($before, $rule->fresh()->body);

        // And the admin is told why, on the page they land back on: a refused
        // save that shows nothing reads as a silent no-op.
        $this->actingAs($this->admin)->get(route('admin.rules.index'))
            ->assertOk()
            ->assertSee('Unknown placeholder')
            // Their rejected text is still in the box they typed it in.
            ->assertSee('{monthly_leave_alowance}');
    }

    public function test_a_reworded_rule_can_be_reset(): void
    {
        $rule = RuleTemplate::where('key', 'leave.allowance')->firstOrFail();
        $original = $rule->default_body;
        $rule->update(['body' => 'Something else entirely.']);

        $this->actingAs($this->admin)
            ->post(route('admin.rules.reset', $rule))
            ->assertRedirect();

        $this->assertSame($original, $rule->fresh()->body);
        $this->assertFalse($rule->fresh()->isEdited());
    }

    public function test_an_instructor_cannot_reach_or_change_the_rules(): void
    {
        $instructor = User::factory()->create();
        $instructor->assignRole('instructor');
        $rule = RuleTemplate::first();

        $this->actingAs($instructor)->get(route('admin.rules.index'))->assertForbidden();
        $this->actingAs($instructor)
            ->put(route('admin.rules.update', $rule), ['body' => 'Nope.'])
            ->assertForbidden();
    }

    public function test_reseeding_keeps_an_admins_wording_but_refreshes_the_default(): void
    {
        $rule = RuleTemplate::where('key', 'leave.allowance')->firstOrFail();
        $rule->update(['body' => 'My own wording, {monthly_leave_allowance} days.', 'default_body' => 'stale default']);

        // What a later deploy does.
        $this->artisan('rules:sync')->assertSuccessful();

        $rule->refresh();
        $this->assertSame('My own wording, {monthly_leave_allowance} days.', $rule->body);
        $this->assertNotSame('stale default', $rule->default_body);
    }

    public function test_sync_takes_a_new_release_wording_for_an_untouched_rule(): void
    {
        $rule = RuleTemplate::where('key', 'leave.allowance')->firstOrFail();
        // What an older release had shipped, never touched by an admin.
        $rule->update(['body' => 'Old wording.', 'default_body' => 'Old wording.']);

        $this->artisan('rules:sync')->assertSuccessful();

        $this->assertStringContainsString('{monthly_leave_allowance}', $rule->fresh()->body);
        $this->assertFalse($rule->fresh()->isEdited());
    }

    public function test_sync_writes_nothing_on_a_dry_run(): void
    {
        $rule = RuleTemplate::where('key', 'leave.allowance')->firstOrFail();
        $rule->update(['body' => 'Old wording.', 'default_body' => 'Old wording.']);

        $this->artisan('rules:sync', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('Old wording.', $rule->fresh()->body);
    }
}
