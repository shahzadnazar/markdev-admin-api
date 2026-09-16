<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use App\Support\ProgressWeights;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four progress components, their checkboxes and their percentages.
 *
 * Settings-backed with constants as the fallback, the same shape as
 * AttendanceWeights — so a database with nothing saved, or one that cannot be
 * reached at all, still produces the defaults rather than zeroes.
 */
class ProgressWeightsSettingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    /** The whole settings form, with progress values overridden per test. */
    protected function payload(array $overrides = []): array
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
            'holiday_announce_days_before' => 3,
            'attendance_weight_present' => 100,
            'attendance_weight_late' => 70,
            'attendance_weight_leave' => 50,
            'attendance_weight_absent' => 0,
            'monthly_leave_allowance' => 2,
            'monthly_absent_allowance' => 2,
            'absent_fine_amount' => 200,
            'attendance_mode' => 'manual',
            'quiz_default_attempts' => 1,
            'quiz_seconds_per_question' => 60,
            'progress_weight_attendance' => 40,
            'progress_weight_quiz' => 20,
            'progress_weight_assignment' => 20,
            'progress_weight_premium' => 20,
            'progress_enabled_attendance' => 1,
            'progress_enabled_quiz' => 1,
            'progress_enabled_assignment' => 1,
            'progress_enabled_premium' => 1,
        ], $overrides);
    }

    protected function save(array $overrides = [])
    {
        return $this->actingAs($this->admin)
            ->from(route('admin.settings.edit'))
            ->put(route('admin.settings.update'), $this->payload($overrides));
    }

    /* ------------------------------- defaults ------------------------------- */

    public function test_the_defaults_are_forty_twenty_twenty_twenty_and_total_100(): void
    {
        $this->assertSame(
            ['attendance' => 40, 'quiz' => 20, 'assignment' => 20, 'premium' => 20],
            ProgressWeights::enabled(),
        );
        $this->assertSame(100, ProgressWeights::checkedTotal());
    }

    public function test_an_unreadable_settings_table_still_gives_the_defaults(): void
    {
        // Setting::cached rescues a missing table; the constants are what the
        // app runs on when the database cannot answer.
        \Illuminate\Support\Facades\Schema::drop('settings');
        Setting::forgetCached();

        $this->assertSame(40, ProgressWeights::percentFor('attendance'));
        $this->assertTrue(ProgressWeights::isEnabled('quiz'));
    }

    public function test_a_stored_value_outside_0_to_100_falls_back(): void
    {
        Setting::updateOrCreate(['key' => 'progress_weight_quiz'], ['value' => 250, 'group' => 'general']);
        Setting::forgetCached();

        $this->assertSame(20, ProgressWeights::percentFor('quiz'), 'a hand-edited row must not skew every figure');
    }

    /* ------------------------------ validation ------------------------------ */

    public function test_checked_percentages_totalling_80_are_refused_by_their_actual_total(): void
    {
        // The message has to name the total; "must total 100%" leaves the admin
        // adding up four boxes by hand to find the one that is wrong.
        $response = $this->save(['progress_weight_attendance' => 20]);

        $response->assertSessionHasErrors([
            'progress_weight_attendance' => 'Checked components must total 100% — they currently total 80%.',
        ]);
    }

    public function test_a_different_wrong_total_names_itself_too(): void
    {
        $this->save(['progress_weight_quiz' => 50])
            ->assertSessionHasErrors([
                'progress_weight_attendance' => 'Checked components must total 100% — they currently total 130%.',
            ]);
    }

    public function test_nothing_checked_is_refused(): void
    {
        $this->save([
            'progress_enabled_attendance' => 0,
            'progress_enabled_quiz' => 0,
            'progress_enabled_assignment' => 0,
            'progress_enabled_premium' => 0,
        ])->assertSessionHasErrors('progress_enabled_premium');
    }

    public function test_only_the_checked_ones_are_added_up(): void
    {
        // Premium off and assignment at 40: 40 + 20 + 40 = 100. Premium's 20 is
        // ignored in the sum even though it is still submitted and stored.
        $this->save([
            'progress_enabled_premium' => 0,
            'progress_weight_assignment' => 40,
        ])->assertSessionHasNoErrors()->assertRedirect();

        Setting::forgetCached();

        $this->assertSame(
            ['attendance' => 40, 'quiz' => 20, 'assignment' => 40],
            ProgressWeights::enabled(),
            'an unchecked component is absent, not present at zero',
        );
        $this->assertSame(100, ProgressWeights::checkedTotal());
    }

    public function test_a_percentage_outside_0_to_100_is_refused(): void
    {
        $this->save(['progress_weight_quiz' => 120])->assertSessionHasErrors('progress_weight_quiz');
        $this->save(['progress_weight_quiz' => -5])->assertSessionHasErrors('progress_weight_quiz');
    }

    public function test_a_non_integer_percentage_is_refused(): void
    {
        $this->save(['progress_weight_quiz' => 12.5])->assertSessionHasErrors('progress_weight_quiz');
    }

    /* --------------------------- the memory rule ---------------------------- */

    /**
     * Unchecking keeps the number; re-checking gets it back.
     *
     * This is why the checkbox and the percentage are separate settings. If
     * unchecking wrote a zero, an academy pausing quizzes for a term would
     * come back to a 0 nobody chose and no way to know what it had been.
     */
    public function test_unchecking_then_rechecking_remembers_the_percentage(): void
    {
        // Quiz is worth 30, with the rest making up 100.
        $this->save([
            'progress_weight_attendance' => 30,
            'progress_weight_quiz' => 30,
            'progress_weight_assignment' => 20,
            'progress_weight_premium' => 20,
        ])->assertSessionHasNoErrors();

        // Turn quizzes off, and put their 30 on attendance by hand.
        $this->save([
            'progress_weight_attendance' => 60,
            'progress_weight_quiz' => 30,
            'progress_weight_assignment' => 20,
            'progress_weight_premium' => 20,
            'progress_enabled_quiz' => 0,
        ])->assertSessionHasNoErrors();

        Setting::forgetCached();
        $this->assertFalse(ProgressWeights::isEnabled('quiz'));
        $this->assertSame(30, ProgressWeights::percentFor('quiz'), 'the number survives being unchecked');
        $this->assertArrayNotHasKey('quiz', ProgressWeights::enabled());

        // Tick it back on, taking attendance back down.
        $this->save([
            'progress_weight_attendance' => 30,
            'progress_weight_quiz' => 30,
            'progress_weight_assignment' => 20,
            'progress_weight_premium' => 20,
            'progress_enabled_quiz' => 1,
        ])->assertSessionHasNoErrors();

        Setting::forgetCached();
        $this->assertSame(30, ProgressWeights::percentFor('quiz'));
    }

    /* -------------------------------- the form ------------------------------ */

    public function test_the_settings_page_shows_every_component(): void
    {
        $page = $this->actingAs($this->admin)->get(route('admin.settings.edit'))->assertOk();

        $page->assertViewHas('settings', fn (array $settings) => array_keys($settings['progress_weights'])
            === array_keys(ProgressWeights::DEFAULTS));

        foreach (ProgressWeights::LABELS as $component => $label) {
            $page->assertSee($label, false);
            $page->assertSee('progress_weight_'.$component, false);
            $page->assertSee('progress_enabled_'.$component, false);
        }
    }

    public function test_coursework_is_the_three_that_are_not_attendance(): void
    {
        // The certificate basis, named in one place.
        $this->assertSame(['quiz', 'assignment', 'premium'], ProgressWeights::COURSEWORK);
        $this->assertNotContains('attendance', ProgressWeights::COURSEWORK);
    }
}
