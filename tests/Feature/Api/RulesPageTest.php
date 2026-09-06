<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceSlot;
use App\Models\DailyAttendance;
use App\Models\Holiday;
use App\Models\RuleTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Support\AttendanceWeights;
use App\Support\RuleBook;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The student Rules page: every number on it traced to a setting.
 */
class RulesPageTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The rules themselves ship in a migration, which RefreshDatabase runs.
        $this->assertGreaterThan(0, RuleTemplate::count(), 'The rule templates migration should have seeded.');
    }

    protected function setting(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value, 'group' => 'general']);
        Setting::forgetCached();
    }

    protected function slot(array $overrides = []): AttendanceSlot
    {
        return AttendanceSlot::create(array_merge([
            'name' => 'Morning',
            'start_time' => '09:00:00',
            'end_time' => '13:00:00',
            'days' => [1, 2, 3, 4, 5],
            'late_after_minutes' => 15,
            'is_active' => true,
            'sort_order' => 1,
        ], $overrides));
    }

    protected function studentOn(?AttendanceSlot $slot): User
    {
        $student = $this->student();
        $student->studentProfile()->create([
            'reg_no' => 'MD-'.uniqid(),
            'attendance_slot_id' => $slot?->id,
        ]);

        return $this->actingAsStudent($student->fresh());
    }

    /** Every sentence the page would show this student, flattened. */
    protected function textFor(?AttendanceSlot $slot = null): string
    {
        $this->studentOn($slot);

        $sections = $this->getJson('/api/v1/rules')->assertOk()->json('data.sections');

        return collect($sections)->pluck('rules')->flatten(1)->pluck('text')->implode("\n");
    }

    /* --------------------------- Weights unchanged -------------------------- */

    public function test_percentages_are_identical_to_the_old_constant(): void
    {
        // The exact numbers the constant produced, over every mix that matters.
        $cases = [
            [['present' => 10], 100.0],
            [['present' => 9, 'late' => 1], 97.0],
            [['present' => 5, 'absent' => 5], 50.0],
            [['late' => 10], 70.0],
            [['leave' => 10], 50.0],
            [['absent' => 10], 0.0],
            // 3x100 + 2x70 + 1x50 + 4x0 = 490 over 10 days.
            [['present' => 3, 'late' => 2, 'leave' => 1, 'absent' => 4], 49.0],
        ];

        foreach ($cases as [$counts, $expected]) {
            $this->assertSame($expected, DailyAttendance::weightedPercent($counts), json_encode($counts));
        }

        // And the same numbers computed straight from the constant, so this
        // test fails if a default is ever quietly changed.
        foreach ($cases as [$counts, $expected]) {
            $days = array_sum($counts);
            $earned = 0;
            foreach ($counts as $status => $n) {
                $earned += $n * DailyAttendance::WEIGHTS[$status];
            }
            $this->assertSame($expected, round($earned / $days, 1));
        }

        $this->assertSame(DailyAttendance::WEIGHTS, AttendanceWeights::all());
    }

    public function test_changing_the_late_weight_recomputes_the_percentage(): void
    {
        $this->assertSame(97.0, DailyAttendance::weightedPercent(['present' => 9, 'late' => 1]));

        $this->setting('attendance_weight_late', 80);

        $this->assertSame(98.0, DailyAttendance::weightedPercent(['present' => 9, 'late' => 1]));
        $this->assertSame(80, AttendanceWeights::for('late'));
    }

    public function test_the_worked_example_follows_the_live_weights(): void
    {
        $this->assertStringContainsString('= 97%', RuleBook::weightedExample());

        $this->setting('attendance_weight_late', 80);

        $this->assertStringContainsString('= 98%', RuleBook::weightedExample());
    }

    public function test_a_nonsense_stored_weight_falls_back_to_the_default(): void
    {
        // The form refuses these; a hand-edited row must not skew every rate.
        $this->setting('attendance_weight_present', 500);
        $this->assertSame(100, AttendanceWeights::for('present'));

        $this->setting('attendance_weight_present', -5);
        $this->assertSame(100, AttendanceWeights::for('present'));

        $this->setting('attendance_weight_present', 'seventy');
        $this->assertSame(100, AttendanceWeights::for('present'));
    }

    public function test_the_weight_table_the_page_serves_matches_the_settings(): void
    {
        $this->setting('attendance_weight_leave', 40);
        $this->studentOn(null);

        $weights = collect($this->getJson('/api/v1/rules')->assertOk()->json('data.weights'))
            ->pluck('weight', 'status');

        $this->assertSame(100, $weights['present']);
        $this->assertSame(70, $weights['late']);
        $this->assertSame(40, $weights['leave']);
        $this->assertSame(0, $weights['absent']);
    }

    /* ------------------------------ Live text ------------------------------ */

    public function test_changing_the_leave_allowance_changes_the_sentence(): void
    {
        $this->setting('monthly_leave_allowance', 2);
        $this->assertStringContainsString('up to 2 leave days per month', $this->textFor());

        $this->setting('monthly_leave_allowance', 5);
        $this->assertStringContainsString('up to 5 leave days per month', $this->textFor());
    }

    public function test_fee_and_fine_figures_come_from_settings(): void
    {
        $this->setting('registration_fee', 3500);
        $this->setting('defaulter_fine_per_day', 250);
        $this->setting('billing_grace_days', 7);
        $this->setting('billing_activation_days', 3);
        $this->setting('monthly_absent_allowance', 4);
        $this->setting('absent_fine_amount', 500);

        $text = $this->textFor();

        $this->assertStringContainsString('PKR 3,500', $text);
        $this->assertStringContainsString('PKR 250 per day', $text);
        $this->assertStringContainsString('7 days after the due date', $text);
        $this->assertStringContainsString('3 days before its due date', $text);
        $this->assertStringContainsString('absent 4 days', $text);
        $this->assertStringContainsString('costs PKR 500', $text);
    }

    public function test_the_recording_mode_sentence_follows_the_setting(): void
    {
        $this->assertStringContainsString('by your instructor', $this->textFor());

        $this->setting('attendance_mode', 'biometric');
        $this->assertStringContainsString('biometric device', $this->textFor());
    }

    public function test_a_reworded_rule_reaches_students_without_a_deploy(): void
    {
        $rule = RuleTemplate::where('key', 'leave.allowance')->firstOrFail();
        $rule->update(['body' => 'Leave: {monthly_leave_allowance} days a month, no more.']);

        $this->assertStringContainsString('Leave: 2 days a month, no more.', $this->textFor());
    }

    public function test_the_page_reports_when_the_rules_last_changed(): void
    {
        $this->studentOn(null);
        $this->assertNotNull($this->getJson('/api/v1/rules')->assertOk()->json('data.updated_at'));
    }

    /* -------------------------------- Slots -------------------------------- */

    public function test_a_student_with_a_slot_sees_their_own_times(): void
    {
        $slot = $this->slot(['name' => 'Evening', 'start_time' => '17:30:00', 'end_time' => '20:00:00', 'late_after_minutes' => 20]);
        $this->studentOn($slot);

        $response = $this->getJson('/api/v1/rules')->assertOk();

        $response->assertJsonPath('data.slot.name', 'Evening')
            ->assertJsonPath('data.slot.starts_at', '5:30 PM')
            ->assertJsonPath('data.slot.ends_at', '8:00 PM')
            ->assertJsonPath('data.slot.late_after_minutes', 20);

        $text = collect($response->json('data.sections'))->pluck('rules')->flatten(1)->pluck('text')->implode("\n");

        // The late rule is judged against this student's own slot, which is
        // what AttendanceConfig::graceMinutesFor actually does — not the
        // academy-wide grace, which only applies to students without a slot.
        $this->assertStringContainsString('more than 20 minutes after 5:30 PM', $text);
        $this->assertStringContainsString('Evening, 5:30 PM – 8:00 PM', $text);
        $this->assertStringNotContainsString('not assigned to a slot', $text);
    }

    public function test_a_student_with_no_slot_sees_the_academy_day(): void
    {
        $this->setting('attendance_day_start', '08:30');
        $this->setting('attendance_late_after_minutes', 10);

        $response = $this->studentOn(null) ? $this->getJson('/api/v1/rules')->assertOk() : null;

        $response->assertJsonPath('data.slot', null);

        $text = collect($response->json('data.sections'))->pluck('rules')->flatten(1)->pluck('text')->implode("\n");

        $this->assertStringContainsString('not assigned to a slot', $text);
        $this->assertStringContainsString('starts at 8:30 AM', $text);
        $this->assertStringContainsString('more than 10 minutes after 8:30 AM', $text);
    }

    /* ------------------------------- Holidays ------------------------------ */

    public function test_upcoming_holidays_are_listed_and_past_ones_are_not(): void
    {
        Carbon::setTestNow('2027-03-15 09:00:00');

        Holiday::create(['date' => '2027-02-05', 'name' => 'Kashmir Day']);
        Holiday::create(['date' => '2027-03-15', 'name' => 'Today Off']);
        Holiday::create(['date' => '2027-03-31', 'name' => 'Eid ul-Fitr']);

        $this->studentOn(null);
        $holidays = collect($this->getJson('/api/v1/rules')->assertOk()->json('data.holidays'));

        // Today counts as upcoming — it has not finished yet.
        $this->assertSame(['Today Off', 'Eid ul-Fitr'], $holidays->pluck('name')->all());
        $this->assertSame('Wednesday, 31 March 2027', $holidays->last()['label']);

        Carbon::setTestNow();
    }

    /* ------------------------------ Resilience ----------------------------- */

    public function test_the_page_still_renders_when_the_settings_table_is_gone(): void
    {
        $this->studentOn(null);

        // Setting::cached rescues a missing table, so everything falls back to
        // its default rather than the page 500ing.
        Schema::drop('settings');
        Setting::forgetCached();

        $response = $this->getJson('/api/v1/rules')->assertOk();

        $weights = collect($response->json('data.weights'))->pluck('weight', 'status');
        $this->assertSame(DailyAttendance::WEIGHTS['present'], $weights['present']);
        $this->assertSame(DailyAttendance::WEIGHTS['late'], $weights['late']);

        $text = collect($response->json('data.sections'))->pluck('rules')->flatten(1)->pluck('text')->implode("\n");
        $this->assertStringContainsString('up to 2 leave days per month', $text);
        $this->assertStringContainsString('= 97%', $text);
    }

    public function test_every_placeholder_in_every_shipped_rule_resolves(): void
    {
        $context = RuleBook::context($this->student());

        foreach (RuleTemplate::all() as $rule) {
            foreach (RuleTemplate::placeholdersIn($rule->body) as $placeholder) {
                $this->assertArrayHasKey(
                    $placeholder,
                    $context,
                    "Rule {$rule->key} uses {{$placeholder}}, which nothing resolves.",
                );
            }
        }
    }

    public function test_no_rendered_sentence_leaves_a_placeholder_behind(): void
    {
        $text = $this->textFor($this->slot());

        $this->assertDoesNotMatchRegularExpression('/\{[a-z0-9_]+\}/i', $text);
    }

    public function test_a_rule_with_nothing_to_say_is_left_out(): void
    {
        $this->setting('support_email', '');
        $this->setting('support_phone', '');

        $sections = collect($this->getJson(
            $this->studentOn(null) ? '/api/v1/rules' : '',
        )->assertOk()->json('data.sections'));

        // No Contact section at all rather than "Email us at ."
        $this->assertNotContains('contact', $sections->pluck('key')->all());

        $this->setting('support_email', 'help@markdev.test');
        $this->studentOn(null);
        $sections = collect($this->getJson('/api/v1/rules')->assertOk()->json('data.sections'));
        $this->assertContains('contact', $sections->pluck('key')->all());
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/rules')->assertUnauthorized();
    }
}
