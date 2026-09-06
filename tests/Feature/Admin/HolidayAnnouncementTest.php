<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\Holiday;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AnnouncementPublished;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A holiday announces itself the day before it starts, not when it is entered.
 */
class HolidayAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $student;

    protected User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['name' => 'Super Admin']);
        $this->admin->assignRole('super-admin');

        $this->student = User::factory()->create();
        $this->student->assignRole('student');

        $this->instructor = User::factory()->create();
        $this->instructor->assignRole('instructor');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function leadTime(int $days): void
    {
        Setting::updateOrCreate(['key' => 'holiday_announce_days_before'], ['value' => $days, 'group' => 'general']);
        Setting::forgetCached();
    }

    /** @param  array<int, string>  $dates */
    protected function holiday(array $dates, string $name): Holiday
    {
        $first = null;

        foreach ($dates as $date) {
            $row = Holiday::create(['date' => $date, 'name' => $name]);
            $first ??= $row;
        }

        return $first;
    }

    protected function announceOn(string $onDate, array $options = []): \Illuminate\Testing\PendingCommand
    {
        Carbon::setTestNow($onDate.' 07:00:00');

        return $this->artisan('holidays:announce-upcoming', $options);
    }

    protected function holidayNotices(): \Illuminate\Database\Eloquent\Collection
    {
        return Announcement::whereNotNull('holiday_id')->orderBy('id')->get();
    }

    /* ------------------------------ The basics ----------------------------- */

    public function test_it_announces_the_day_before_and_tells_students_and_instructors(): void
    {
        Notification::fake();
        $this->leadTime(1);
        $this->holiday(['2027-03-31'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-30')->assertSuccessful();

        $notices = $this->holidayNotices();
        $this->assertCount(1, $notices);
        $this->assertSame('Eid ul-Fitr — the academy is closed tomorrow, 31 March.', $notices->first()->title);
        // Academy-wide: a closure is not a fact about one course.
        $this->assertNull($notices->first()->course_id);

        Notification::assertSentTo($this->student, AnnouncementPublished::class);
        Notification::assertSentTo($this->instructor, AnnouncementPublished::class);
        // Nobody is told about their own post.
        Notification::assertNotSentTo($this->admin, AnnouncementPublished::class);
    }

    public function test_nothing_is_sent_when_the_holiday_is_entered(): void
    {
        Notification::fake();
        $this->leadTime(1);

        // January, for a holiday in March.
        Carbon::setTestNow('2027-01-05 09:00:00');
        $this->actingAs($this->admin)
            ->post(route('admin.holidays.store'), ['name' => 'Eid ul-Fitr', 'date' => '2027-03-31'])
            ->assertRedirect();

        $this->assertCount(0, $this->holidayNotices());
        Notification::assertNothingSent();
    }

    public function test_a_second_run_on_the_same_morning_sends_nothing_more(): void
    {
        Notification::fake();
        $this->leadTime(1);
        $this->holiday(['2027-03-31'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-30')->assertSuccessful();
        $this->announceOn('2027-03-30')->assertSuccessful();

        $this->assertCount(1, $this->holidayNotices());
        Notification::assertSentToTimes($this->student, AnnouncementPublished::class, 1);
    }

    public function test_a_run_before_the_notice_is_due_sends_nothing(): void
    {
        $this->leadTime(1);
        $this->holiday(['2027-03-31'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-29')->assertSuccessful();

        $this->assertCount(0, $this->holidayNotices());
    }

    public function test_the_run_on_the_holiday_itself_adds_nothing(): void
    {
        $this->leadTime(1);
        $this->holiday(['2027-03-31'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-30')->assertSuccessful();
        $this->announceOn('2027-03-31')->assertSuccessful();

        $this->assertCount(1, $this->holidayNotices());
    }

    /* -------------------------------- Ranges ------------------------------- */

    public function test_a_three_day_closure_is_one_notice_naming_the_range(): void
    {
        $this->leadTime(1);
        $this->holiday(['2027-03-31', '2027-04-01', '2027-04-02'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-30')->assertSuccessful();

        $notices = $this->holidayNotices();
        $this->assertCount(1, $notices);
        $this->assertSame('Eid ul-Fitr — the academy is closed 31 March to 2 April.', $notices->first()->title);

        // And nothing further on the days inside the range.
        $this->announceOn('2027-03-31')->assertSuccessful();
        $this->announceOn('2027-04-01')->assertSuccessful();
        $this->assertCount(1, $this->holidayNotices());
    }

    public function test_two_different_holidays_on_consecutive_days_are_not_merged(): void
    {
        $this->leadTime(1);
        $this->holiday(['2027-02-05'], 'Kashmir Day');
        $this->holiday(['2027-02-06'], 'Founders Day');

        $this->announceOn('2027-02-04')->assertSuccessful();
        $this->announceOn('2027-02-05')->assertSuccessful();

        $notices = $this->holidayNotices();
        $this->assertCount(2, $notices);
        // A run is consecutive dates *with the same name*, so these stay two
        // closures rather than becoming one false 5–6 February range.
        $this->assertSame(
            [
                'Kashmir Day — the academy is closed tomorrow, 5 February.',
                'Founders Day — the academy is closed tomorrow, 6 February.',
            ],
            $notices->pluck('title')->all(),
        );
    }

    public function test_the_notice_expires_once_the_academy_reopens(): void
    {
        $this->leadTime(1);
        $this->holiday(['2027-03-31', '2027-04-01', '2027-04-02'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-30')->assertSuccessful();
        $notice = $this->holidayNotices()->first();

        // Still up on the second day of the closure — a fixed 24 hours from
        // publication would already have dropped it.
        Carbon::setTestNow('2027-04-01 10:00:00');
        $this->assertTrue($notice->fresh()->isLive());
        $this->assertSame(1, Announcement::live()->count());

        // Gone once the academy is open again.
        Carbon::setTestNow('2027-04-04 10:00:00');
        $this->assertFalse($notice->fresh()->isLive());
        $this->assertSame(0, Announcement::live()->count());
    }

    /* ------------------------------ Lead time ------------------------------ */

    public function test_a_longer_lead_time_moves_the_notice_earlier(): void
    {
        $this->leadTime(3);
        $this->holiday(['2027-03-31'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-27')->assertSuccessful();
        $this->assertCount(0, $this->holidayNotices());

        $this->announceOn('2027-03-28')->assertSuccessful();
        $notices = $this->holidayNotices();
        $this->assertCount(1, $notices);
        // Not "tomorrow" — it is not, and the wording says the day instead.
        $this->assertSame('Eid ul-Fitr — the academy is closed on Wednesday, 31 March.', $notices->first()->title);
    }

    public function test_changing_the_lead_time_takes_effect_on_the_next_run(): void
    {
        $this->leadTime(1);
        $this->holiday(['2027-03-31'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-28')->assertSuccessful();
        $this->assertCount(0, $this->holidayNotices());

        // An admin widens it in Settings; nothing is redeployed.
        $this->leadTime(3);

        $this->announceOn('2027-03-28')->assertSuccessful();
        $this->assertCount(1, $this->holidayNotices());
    }

    public function test_the_settings_form_saves_the_lead_time_and_refuses_zero(): void
    {
        Notification::fake();

        $payload = [
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
            'holiday_announce_days_before' => 5,
            'monthly_leave_allowance' => 2,
            'monthly_absent_allowance' => 2,
            'absent_fine_amount' => 500,
            'attendance_mode' => 'manual',
        ];

        $this->actingAs($this->admin)->put(route('admin.settings.update'), $payload)->assertRedirect();
        Setting::forgetCached();
        $this->assertSame(5, \App\Support\AcademyCalendar::announceDaysBefore());

        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), [...$payload, 'holiday_announce_days_before' => 0])
            ->assertSessionHasErrors('holiday_announce_days_before');
    }

    /* ------------------------------ Late entry ----------------------------- */

    public function test_a_holiday_entered_after_its_notice_was_due_is_announced_at_once(): void
    {
        $this->leadTime(1);

        // Entered on the 30th for the 31st — the notice was due that morning,
        // and the run later that day still catches it. Late notice beats none.
        $this->holiday(['2027-03-31'], 'Eid ul-Fitr');
        $this->announceOn('2027-03-30')->assertSuccessful();

        $this->assertCount(1, $this->holidayNotices());
    }

    public function test_a_holiday_entered_on_the_day_it_starts_still_gets_a_notice(): void
    {
        $this->leadTime(1);
        $this->holiday(['2027-03-31'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-31')->assertSuccessful();

        $notices = $this->holidayNotices();
        $this->assertCount(1, $notices);
        // "Today", because that is what it is — the wording is not left saying
        // tomorrow just because that is what a timely notice would have said.
        $this->assertSame('Eid ul-Fitr — the academy is closed today, 31 March.', $notices->first()->title);
    }

    public function test_a_closure_that_has_already_ended_is_not_announced(): void
    {
        $this->leadTime(1);
        $this->holiday(['2027-03-31'], 'Eid ul-Fitr');

        $this->announceOn('2027-04-02')->assertSuccessful();

        $this->assertCount(0, $this->holidayNotices());
    }

    public function test_catch_up_reaches_a_closure_that_has_ended(): void
    {
        $this->leadTime(1);
        $this->holiday(['2027-03-31'], 'Eid ul-Fitr');

        $this->announceOn('2027-04-02', ['--catch-up' => 7])->assertSuccessful();

        $this->assertCount(1, $this->holidayNotices());
    }

    /* --------------------------- Changed or removed ------------------------ */

    public function test_removing_a_holiday_withdraws_its_notice(): void
    {
        Notification::fake();
        $this->leadTime(1);
        $holiday = $this->holiday(['2027-03-31'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-30')->assertSuccessful();
        $notice = $this->holidayNotices()->first();

        Carbon::setTestNow('2027-03-30 12:00:00');
        $this->actingAs($this->admin)
            ->delete(route('admin.holidays.destroy', $holiday))
            ->assertRedirect();

        // Withdrawn, not erased: soft-deleted like the holiday itself, so the
        // audit trail survives while the claim does not.
        $this->assertSoftDeleted('announcements', ['id' => $notice->id]);
        $this->assertSame(0, Announcement::live()->count());
    }

    public function test_a_holiday_the_command_finds_removed_has_its_notice_withdrawn(): void
    {
        $this->leadTime(1);
        $holiday = $this->holiday(['2027-03-31'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-30')->assertSuccessful();
        $notice = $this->holidayNotices()->first();

        // Removed outside the panel — a seeder, a console, a future bulk tool.
        Carbon::setTestNow('2027-03-30 12:00:00');
        $holiday->delete();

        $this->announceOn('2027-03-30')->assertSuccessful();

        $this->assertSoftDeleted('announcements', ['id' => $notice->id]);
    }

    public function test_moving_a_holiday_rewrites_its_notice(): void
    {
        Notification::fake();
        $this->leadTime(1);
        $holiday = $this->holiday(['2027-03-31'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-30')->assertSuccessful();
        $notice = $this->holidayNotices()->first();
        $this->assertStringContainsString('31 March', $notice->title);

        Carbon::setTestNow('2027-03-30 12:00:00');
        $this->actingAs($this->admin)
            ->put(route('admin.holidays.update', $holiday), ['name' => 'Eid ul-Fitr', 'date' => '2027-04-05'])
            ->assertRedirect();

        $notice->refresh();
        $this->assertStringContainsString('5 April', $notice->title);
        $this->assertStringNotContainsString('31 March', $notice->title);
        // Its live window follows the dates too.
        $this->assertSame('2027-04-06', $notice->live_until->toDateString());

        // Still the one bell from the original notice: a correction rewrites
        // what it says rather than announcing itself as a new closure.
        Notification::assertSentToTimes($this->student, AnnouncementPublished::class, 1);
        Notification::assertSentToTimes($this->instructor, AnnouncementPublished::class, 1);
    }

    public function test_a_shortened_range_has_its_notice_rewritten(): void
    {
        $this->leadTime(1);
        $this->holiday(['2027-03-31', '2027-04-01', '2027-04-02'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-30')->assertSuccessful();
        $notice = $this->holidayNotices()->first();
        $this->assertStringContainsString('31 March to 2 April', $notice->title);

        Carbon::setTestNow('2027-03-30 12:00:00');
        // Half-open, never an equality or a whereIn: `date` is a date-cast
        // column and the stored value comes back as a full datetime, so
        // matching on the bare date string would delete nothing and this test
        // would quietly assert against an unchanged range.
        Holiday::onDate('2027-04-02')->get()->each->delete();

        $this->announceOn('2027-03-30')->assertSuccessful();

        $this->assertStringContainsString('31 March to 1 April', $notice->fresh()->title);
        $this->assertCount(1, $this->holidayNotices());
    }

    public function test_trimming_the_first_day_shrinks_the_notice_rather_than_withdrawing_it(): void
    {
        Notification::fake();
        $this->leadTime(1);
        $this->holiday(['2027-03-31', '2027-04-01', '2027-04-02'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-30')->assertSuccessful();
        $notice = $this->holidayNotices()->first();
        $anchor = $notice->holiday_id;

        // The day the notice is anchored to is exactly the one removed.
        Carbon::setTestNow('2027-03-30 12:00:00');
        Holiday::onDate('2027-03-31')->get()->each->delete();

        $this->announceOn('2027-03-30')->assertSuccessful();

        // Still one notice, still standing, now naming what is left of the
        // closure — not withdrawn and re-posted, which would ring a second
        // bell for a closure everyone has already been told about.
        $notices = $this->holidayNotices();
        $this->assertCount(1, $notices);
        $this->assertSame('Eid ul-Fitr — the academy is closed 1 April to 2 April.', $notices->first()->title);
        $this->assertNotSame($anchor, $notices->first()->holiday_id);
        Notification::assertSentToTimes($this->student, AnnouncementPublished::class, 1);
    }

    public function test_removing_every_day_still_withdraws_the_notice(): void
    {
        $this->leadTime(1);
        $this->holiday(['2027-03-31', '2027-04-01'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-30')->assertSuccessful();
        $notice = $this->holidayNotices()->first();

        Carbon::setTestNow('2027-03-30 12:00:00');
        Holiday::onDate('2027-03-31')->get()->each->delete();
        Holiday::onDate('2027-04-01')->get()->each->delete();

        $this->announceOn('2027-03-30')->assertSuccessful();

        $this->assertSoftDeleted('announcements', ['id' => $notice->id]);
    }

    public function test_a_separate_holiday_with_the_same_name_does_not_capture_the_notice(): void
    {
        $this->leadTime(1);
        $this->holiday(['2027-03-31'], 'Eid ul-Fitr');
        // Same name, months away — a different closure entirely.
        $this->holiday(['2027-06-15'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-30')->assertSuccessful();
        $notice = $this->holidayNotices()->first();

        Carbon::setTestNow('2027-03-30 12:00:00');
        Holiday::onDate('2027-03-31')->get()->each->delete();

        $this->announceOn('2027-03-30')->assertSuccessful();

        // Withdrawn, not quietly re-pointed at June.
        $this->assertSoftDeleted('announcements', ['id' => $notice->id]);
    }

    /* -------------------------------- Shape -------------------------------- */

    public function test_a_dry_run_writes_nothing(): void
    {
        Notification::fake();
        $this->leadTime(1);
        $this->holiday(['2027-03-31'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-30', ['--dry-run' => true])->assertSuccessful();

        $this->assertCount(0, $this->holidayNotices());
        Notification::assertNothingSent();
    }

    public function test_it_says_nothing_when_there_is_no_admin_to_post_as(): void
    {
        $this->leadTime(1);
        $this->admin->delete();
        $this->holiday(['2027-03-31'], 'Eid ul-Fitr');

        $this->announceOn('2027-03-30')->assertSuccessful();

        $this->assertCount(0, $this->holidayNotices());
    }
}
