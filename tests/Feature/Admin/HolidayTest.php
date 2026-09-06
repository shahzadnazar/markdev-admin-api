<?php

namespace Tests\Feature\Admin;

use App\Models\Holiday;
use App\Models\Setting;
use App\Models\User;
use App\Support\AcademyCalendar;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The two ways an admin says the academy is closed: the weekly pattern in
 * Settings, and dated holidays.
 */
class HolidayTest extends TestCase
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

    /* ------------------------------ Holidays ------------------------------- */

    public function test_a_range_expands_into_one_row_per_date(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.holidays.store'), [
                'name' => 'Eid ul-Fitr',
                'date' => '2027-03-20',
                'to_date' => '2027-03-22',
            ])
            ->assertRedirect();

        $this->assertSame(3, Holiday::count());
        $this->assertSame(
            ['2027-03-20', '2027-03-21', '2027-03-22'],
            Holiday::orderBy('date')->get()->map(fn (Holiday $h) => $h->date->toDateString())->all(),
        );
        $this->assertSame(['Eid ul-Fitr'], Holiday::pluck('name')->unique()->values()->all());
    }

    public function test_a_single_day_needs_no_range(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.holidays.store'), ['name' => 'Independence Day', 'date' => '2027-08-14'])
            ->assertRedirect();

        $this->assertSame(1, Holiday::count());
    }

    public function test_a_date_that_is_already_a_holiday_is_refused_with_a_message(): void
    {
        Holiday::create(['date' => '2027-08-14', 'name' => 'Independence Day']);

        // A message, not a unique-constraint exception: `date` is a date-cast
        // column, so the clash has to be found before the insert.
        $this->actingAs($this->admin)
            ->post(route('admin.holidays.store'), ['name' => 'Something else', 'date' => '2027-08-14'])
            ->assertSessionHasErrors('date');

        $this->assertSame(1, Holiday::count());
    }

    public function test_a_range_overlapping_an_existing_holiday_is_refused_whole(): void
    {
        Holiday::create(['date' => '2027-03-21', 'name' => 'Eid ul-Fitr']);

        $this->actingAs($this->admin)
            ->post(route('admin.holidays.store'), [
                'name' => 'Eid ul-Fitr',
                'date' => '2027-03-20',
                'to_date' => '2027-03-22',
            ])
            ->assertSessionHasErrors('date');

        // Nothing partially written: the whole range is refused, so an admin
        // fixes one thing rather than reconciling what got through.
        $this->assertSame(1, Holiday::count());
    }

    public function test_a_backwards_range_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.holidays.store'), [
                'name' => 'Eid', 'date' => '2027-03-22', 'to_date' => '2027-03-20',
            ])
            ->assertSessionHasErrors('to_date');
    }

    public function test_a_holiday_can_be_renamed_and_moved(): void
    {
        $holiday = Holiday::create(['date' => '2027-08-14', 'name' => 'Independance Day']);

        $this->actingAs($this->admin)
            ->put(route('admin.holidays.update', $holiday), ['name' => 'Independence Day', 'date' => '2027-08-15'])
            ->assertRedirect();

        $holiday->refresh();
        $this->assertSame('Independence Day', $holiday->name);
        $this->assertSame('2027-08-15', $holiday->date->toDateString());
    }

    public function test_removing_a_holiday_leaves_settled_days_alone(): void
    {
        $holiday = Holiday::create(['date' => '2027-08-14', 'name' => 'Independence Day']);
        $student = User::factory()->create();
        $student->assignRole('student');
        \App\Models\DailyAttendance::create([
            'user_id' => $student->id,
            'date' => '2027-08-14',
            'status' => \App\Models\DailyAttendance::HOLIDAY,
            'remarks' => 'Independence Day',
            'source' => 'auto',
            'marked_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.holidays.destroy', $holiday))
            ->assertRedirect();

        $this->assertFalse(AcademyCalendar::isHoliday('2027-08-14'));
        // Rewriting settled history would turn a day people have already seen
        // into an absence, and an absence is billable.
        $this->assertSame(
            \App\Models\DailyAttendance::HOLIDAY,
            \App\Models\DailyAttendance::where('user_id', $student->id)->value('status'),
        );
    }

    public function test_an_instructor_cannot_reach_the_holiday_screens(): void
    {
        $instructor = User::factory()->create();
        $instructor->assignRole('instructor');

        $this->actingAs($instructor)->get(route('admin.holidays.index'))->assertForbidden();
        $this->actingAs($instructor)
            ->post(route('admin.holidays.store'), ['name' => 'Nope', 'date' => '2027-08-14'])
            ->assertForbidden();
    }

    public function test_the_screens_render(): void
    {
        Holiday::create(['date' => '2027-08-14', 'name' => 'Independence Day']);
        $holiday = Holiday::create(['date' => today()->addWeek()->toDateString(), 'name' => 'Eid ul-Fitr']);

        $this->actingAs($this->admin)->get(route('admin.holidays.index', ['year' => 2027]))
            ->assertOk()->assertSee('Independence Day')->assertSee('Mon–Fri');
        $this->actingAs($this->admin)->get(route('admin.holidays.create'))->assertOk()->assertSee('Last day (optional)');
        $this->actingAs($this->admin)->get(route('admin.holidays.edit', $holiday))->assertOk()->assertSee('Eid ul-Fitr');

        // The working-day picker and the holidays link both live on Settings.
        $this->actingAs($this->admin)->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('Academy working days')
            ->assertSee('Manage holidays')
            ->assertSee('Eid ul-Fitr');
    }

    /* --------------------------- Working days ------------------------------ */

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
            // Required since the holiday announcer landed: how far ahead a
            // closure is announced, minimum 1.
            'holiday_announce_days_before' => 1,
            // Required since the weights moved out of the constant.
            'attendance_weight_present' => 100,
            'attendance_weight_late' => 70,
            'attendance_weight_leave' => 50,
            'attendance_weight_absent' => 0,
            'monthly_leave_allowance' => 2,
            'monthly_absent_allowance' => 2,
            'absent_fine_amount' => 500,
            'attendance_mode' => 'manual',
        ], $overrides);
    }

    public function test_working_days_are_saved_as_sorted_iso_numbers(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), $this->settingsPayload([
                'academy_working_days' => ['6', '1', '3'],
            ]))
            ->assertRedirect();

        Setting::forgetCached();

        // Same shape as a slot's own days, so the two lists can be compared
        // without translating between them.
        $this->assertSame([1, 3, 6], AcademyCalendar::workingDays());
    }

    public function test_an_academy_that_never_opens_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), $this->settingsPayload(['academy_working_days' => []]))
            ->assertSessionHasErrors('academy_working_days');

        Setting::forgetCached();
        $this->assertNull(Setting::cached('academy_working_days'));
    }

    public function test_a_nonsense_weekday_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), $this->settingsPayload(['academy_working_days' => [0, 9]]))
            ->assertSessionHasErrors('academy_working_days.0');
    }

    public function test_the_default_working_week_is_monday_to_friday(): void
    {
        $this->assertSame([1, 2, 3, 4, 5], AcademyCalendar::workingDays());
    }

    public function test_a_stored_empty_list_still_falls_back_rather_than_closing_the_academy(): void
    {
        // The form refuses this, but a hand-edited row must not switch
        // attendance off for everybody.
        Setting::updateOrCreate(['key' => 'academy_working_days'], ['value' => [], 'group' => 'general']);
        Setting::forgetCached();

        $this->assertSame([1, 2, 3, 4, 5], AcademyCalendar::workingDays());
    }
}
