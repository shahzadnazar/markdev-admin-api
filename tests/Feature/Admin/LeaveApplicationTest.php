<?php

namespace Tests\Feature\Admin;

use App\Models\DailyAttendance;
use App\Models\LeaveApplication;
use App\Models\Setting;
use App\Models\User;
use App\Support\AttendanceConfig;
use App\Support\LeaveAllowance;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Student leave: applying, reviewing, and what it costs the monthly allowance.
 *
 * ## Why the clock is frozen
 *
 * Most of this file builds ranges from `today()->addDays(1..7)` and then asserts
 * on the MONTH NAME the balance message carries — "Only 1 leave remaining in
 * September." Run on a day close enough to a month end and the range crosses
 * into the next month, the allowance is checked against THAT month, and the
 * assertion fails on a name that was never wrong. Roughly one day in five,
 * including every 29th, 30th and 31st.
 *
 * Three tests failed that way for the whole of this build and were reported each
 * time as "pre-existing and date-relative", which is exactly the problem: "3
 * failures" became background noise, and the day a real fourth appeared nobody
 * would have looked.
 *
 * ## The date, and why this one
 *
 * MONDAY 8 NOVEMBER 2027.
 *
 *   a Monday — so the working-week arithmetic starts at the beginning of a week
 *              rather than in the middle of one. It does not change any figure
 *              TODAY, because setUp calls academyOpensEveryDay() and the academy
 *              is therefore open seven days a week in this file; it matters the
 *              day somebody removes that call, which is precisely when a
 *              deliberate weekday stops being decoration.
 *
 *   the 8th  — the furthest any test reaches is `addDays(11)` (19 Nov) forwards
 *              and `subDays(2)` (6 Nov) back, so every range this file builds
 *              stays inside one month with room on both sides. That is the whole
 *              bug, fixed by choosing a date rather than by weakening an
 *              assertion.
 *
 *   November — no holiday is seeded anywhere in this file, so no month here has
 *              one; November is named rather than inherited so that a fixture
 *              that later adds holidays has a month to avoid.
 *
 *   2027     — beyond anything real, and outside February, March, April and May
 *              2027, the four months the tests below travel to on their own.
 *
 * ## The tests that travel somewhere else
 *
 * Six of them do, and they keep their own dates: `travelTo` is Carbon::setTestNow
 * under the hood, so a later call replaces this one rather than stacking with it.
 * Verified rather than assumed — test_a_new_month_starts_the_balance_over travels
 * to April and then May 2027 and asserts a reset that only happens if this freeze
 * was genuinely overridden.
 */
class LeaveApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        // Before anything is seeded or created, so every row this fixture writes
        // carries the frozen time too.
        $this->travelTo(Carbon::parse('2027-11-08 09:00'));

        $this->seed(RolePermissionSeeder::class);
        $this->academyOpensEveryDay();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->student = User::factory()->create(['name' => 'Aliya Khan']);
        $this->student->assignRole('student');

        // Wide enough that the tests about reviewing are not really tests
        // about the allowance; the allowance ones set their own.
        $this->setAllowance(5);
    }

    protected function setAllowance(int $days): void
    {
        Setting::updateOrCreate(
            ['key' => 'monthly_leave_allowance'],
            ['value' => $days, 'group' => 'general'],
        );
        Setting::forgetCached();
    }

    protected function balance(?User $student = null, ?Carbon $month = null): array
    {
        return LeaveAllowance::balance(($student ?? $this->student)->id, $month ?? now());
    }

    protected function makeLeave(array $overrides = []): LeaveApplication
    {
        return LeaveApplication::create(array_merge([
            'user_id' => $this->student->id,
            'from_date' => today()->toDateString(),
            'to_date' => today()->addDays(2)->toDateString(),
            'reason' => 'Family wedding out of town',
        ], $overrides));
    }

    protected function attendanceRow(string $date, string $status): DailyAttendance
    {
        return DailyAttendance::create([
            'user_id' => $this->student->id,
            'date' => $date,
            'status' => $status,
            'source' => 'manual',
            'marked_by' => $this->admin->id,
            'marked_at' => now(),
        ]);
    }

    /**
     * The freeze is in effect, and a travelling test really does override it.
     *
     * Pinned because the freeze is invisible: without it these tests fail on
     * about one day in five, and nothing else in the file would say why. If
     * somebody removes the travelTo in setUp, this fails on the spot with a
     * reason, instead of three other tests failing intermittently for months.
     *
     * The second half is the claim the docblock makes about the six tests that
     * travel somewhere else — checked here rather than asserted in prose.
     */
    public function test_the_clock_is_frozen_where_setup_put_it(): void
    {
        $this->assertSame('2027-11-08', today()->toDateString());
        $this->assertSame('Monday', today()->format('l'));

        // Room on both sides, which is the whole point of the 8th: every range
        // this file builds stays inside November.
        $this->assertSame('November', today()->subDays(2)->format('F'));
        $this->assertSame('November', today()->addDays(11)->format('F'));

        // And a test that travels lands where it asked, not here.
        $this->travelTo(Carbon::parse('2027-04-10 09:00'));
        $this->assertSame('2027-04-10', today()->toDateString());
    }

    /* ------------------------------ Student API ---------------------------- */

    public function test_student_can_apply_for_leave_via_api(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson('/api/v1/leaves', [
            'from_date' => today()->addDay()->toDateString(),
            'to_date' => today()->addDays(3)->toDateString(),
            'reason' => 'Family wedding out of town',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.days_count', 3)
            ->assertJsonPath('data.reason', 'Family wedding out of town');

        $this->assertDatabaseHas('leave_applications', [
            'user_id' => $this->student->id,
            'status' => 'pending',
        ]);
    }

    public function test_overlapping_application_is_rejected_with_422(): void
    {
        $this->makeLeave(['from_date' => today()->addDays(2)->toDateString(), 'to_date' => today()->addDays(4)->toDateString()]);

        Sanctum::actingAs($this->student);

        $this->postJson('/api/v1/leaves', [
            'from_date' => today()->addDays(4)->toDateString(),
            'to_date' => today()->addDays(6)->toDateString(),
            'reason' => 'Extending the trip',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from_date');

        $this->assertSame(1, LeaveApplication::count());
    }

    public function test_a_rejected_application_does_not_block_reapplying(): void
    {
        $this->makeLeave([
            'from_date' => today()->addDays(2)->toDateString(),
            'to_date' => today()->addDays(4)->toDateString(),
            'status' => 'rejected',
        ]);

        Sanctum::actingAs($this->student);

        $this->postJson('/api/v1/leaves', [
            'from_date' => today()->addDays(2)->toDateString(),
            'to_date' => today()->addDays(4)->toDateString(),
            'reason' => 'Reapplying with more detail',
        ])->assertCreated();
    }

    public function test_leave_dates_are_validated(): void
    {
        Sanctum::actingAs($this->student);

        // In the past
        $this->postJson('/api/v1/leaves', [
            'from_date' => today()->subDay()->toDateString(),
            'to_date' => today()->toDateString(),
            'reason' => 'Backdated',
        ])->assertUnprocessable()->assertJsonValidationErrors('from_date');

        // Ends before it starts
        $this->postJson('/api/v1/leaves', [
            'from_date' => today()->addDays(3)->toDateString(),
            'to_date' => today()->addDay()->toDateString(),
            'reason' => 'Backwards range',
        ])->assertUnprocessable()->assertJsonValidationErrors('to_date');

        // Too far ahead
        $this->postJson('/api/v1/leaves', [
            'from_date' => today()->addDays(59)->toDateString(),
            'to_date' => today()->addDays(90)->toDateString(),
            'reason' => 'Sabbatical',
        ])->assertUnprocessable()->assertJsonValidationErrors('to_date');
    }

    public function test_student_only_sees_their_own_applications(): void
    {
        $other = User::factory()->create();
        $other->assignRole('student');

        $this->makeLeave();
        LeaveApplication::create([
            'user_id' => $other->id,
            'from_date' => today()->toDateString(),
            'to_date' => today()->toDateString(),
            'reason' => 'Someone else',
        ]);

        Sanctum::actingAs($this->student);

        $this->getJson('/api/v1/leaves')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'Family wedding out of town');
    }

    /* ------------------------------ Admin review --------------------------- */

    public function test_admin_screen_lists_pending_applications(): void
    {
        $this->makeLeave();

        $this->actingAs($this->admin)->get('/admin/leaves')
            ->assertOk()
            ->assertSee('Leave requests')
            ->assertSee('Aliya Khan')
            ->assertSee('Family wedding out of town');
    }

    public function test_an_instructor_with_no_category_sees_no_leave_requests(): void
    {
        // Instructors reach this screen now, scoped to the categories they
        // teach in. This one teaches nothing, so the list is empty rather than
        // showing the academy's requests.
        $instructor = User::factory()->create();
        $instructor->assignRole('instructor');

        $this->actingAs($instructor)->get('/admin/leaves')
            ->assertOk()
            ->assertViewHas('pendingCount', 0);
    }

    protected function review(LeaveApplication $leave, array $payload = []): TestResponse
    {
        return $this->actingAs($this->admin)->post("/admin/leaves/{$leave->id}/review", $payload);
    }

    public function test_part_of_a_range_can_be_approved_and_the_rest_declined(): void
    {
        // Five days, three approved.
        $leave = $this->makeLeave(['to_date' => today()->addDays(4)->toDateString()]);
        $approved = [
            today()->toDateString(),
            today()->addDay()->toDateString(),
            today()->addDays(3)->toDateString(),
        ];

        $this->review($leave, ['days' => $approved, 'review_note' => 'The other two clash with the assessment.'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $leave->refresh();
        $this->assertSame('partially_approved', $leave->status);
        $this->assertSame(3, $leave->decisions()->where('status', 'approved')->count());
        $this->assertSame(2, $leave->decisions()->where('status', 'declined')->count());
        $this->assertEqualsCanonicalizing($approved, $leave->approvedDates());

        // Reviewing decides; it never writes the register.
        $this->assertSame(0, DailyAttendance::count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'leave_reviewed',
            'module' => 'leave_applications',
            'record_id' => $leave->id,
        ]);
    }

    public function test_declining_any_day_without_a_note_is_rejected(): void
    {
        $leave = $this->makeLeave(); // today .. today+2

        $this->review($leave, ['days' => [today()->toDateString()]])
            ->assertSessionHasErrors('review_note');

        $this->review($leave, ['decline_all' => 1])
            ->assertSessionHasErrors('review_note');

        $leave->refresh();
        $this->assertSame('pending', $leave->status);
        $this->assertSame(0, $leave->decisions()->count());
    }

    public function test_approving_every_day_needs_no_note(): void
    {
        $leave = $this->makeLeave();
        $all = collect($leave->days())->map->toDateString()->all();

        $this->review($leave, ['days' => $all])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertNull($leave->review_note);
        $this->assertSame(3, $leave->decisions()->where('status', 'approved')->count());
    }

    public function test_decline_all_records_every_day_as_declined(): void
    {
        $leave = $this->makeLeave();

        $this->review($leave, ['decline_all' => 1, 'review_note' => 'Too close to finals'])
            ->assertSessionHas('success');

        $leave->refresh();
        $this->assertSame('rejected', $leave->status);
        $this->assertSame('Too close to finals', $leave->review_note);
        $this->assertSame(3, $leave->decisions()->where('status', 'declined')->count());
        $this->assertSame([], $leave->approvedDates());
        $this->assertSame(0, DailyAttendance::count());
    }

    public function test_the_student_is_told_which_days_were_approved(): void
    {
        $leave = $this->makeLeave();

        $this->review($leave, [
            'days' => [today()->toDateString()],
            'review_note' => 'Only the first day.',
        ]);

        $data = $this->student->notifications()->first()->data;
        $this->assertSame('Leave partly approved', $data['title']);
        $this->assertStringContainsString('1 day(s) were approved', $data['message']);
        $this->assertStringContainsString('Only the first day.', $data['message']);

        // And the portal sees the per-day verdicts, not one rollup.
        Sanctum::actingAs($this->student);
        $this->getJson('/api/v1/leaves')->assertOk()
            ->assertJsonPath('data.0.status', 'partially_approved')
            ->assertJsonPath('data.0.approved_days', 1)
            ->assertJsonPath('data.0.declined_days', 2)
            ->assertJsonPath('data.0.days.0.status', 'approved')
            ->assertJsonPath('data.0.days.1.status', 'declined')
            ->assertJsonPath('data.0.review_note', 'Only the first day.');
    }

    public function test_non_pending_applications_cannot_be_reviewed_again(): void
    {
        $leave = $this->makeLeave();
        $all = collect($leave->days())->map->toDateString()->all();

        $this->review($leave, ['days' => $all]);

        $this->review($leave, ['decline_all' => 1, 'review_note' => 'Changed my mind'])
            ->assertSessionHas('error');

        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertNull($leave->review_note);

        // Only the original review notified the student.
        $this->assertSame(1, $this->student->notifications()->count());
    }

    /* ------------------- Approval reaches the register at close ------------- */

    public function test_an_approved_day_becomes_leave_when_that_day_closes(): void
    {
        $leave = $this->makeLeave(['from_date' => today()->toDateString(), 'to_date' => today()->toDateString()]);
        $this->review($leave, ['days' => [today()->toDateString()]]);

        $this->assertSame(0, DailyAttendance::count());

        $this->artisan('attendance:close-day')->assertSuccessful();

        $row = DailyAttendance::onDate(today())->where('user_id', $this->student->id)->first();
        $this->assertSame('leave', $row->status);
        $this->assertSame('Approved leave', $row->remarks);
    }

    public function test_a_student_who_turned_up_stays_present(): void
    {
        $leave = $this->makeLeave(['from_date' => today()->toDateString(), 'to_date' => today()->toDateString()]);
        $this->review($leave, ['days' => [today()->toDateString()]]);

        $this->attendanceRow(today()->toDateString(), 'present');

        $this->artisan('attendance:close-day')->assertSuccessful();

        $row = DailyAttendance::onDate(today())->where('user_id', $this->student->id)->first();
        $this->assertSame('present', $row->status);
    }

    public function test_a_declined_day_becomes_absent_when_that_day_closes(): void
    {
        $leave = $this->makeLeave(['from_date' => today()->toDateString(), 'to_date' => today()->toDateString()]);
        $this->review($leave, ['decline_all' => 1, 'review_note' => 'Not this time']);

        $this->artisan('attendance:close-day')->assertSuccessful();

        $row = DailyAttendance::onDate(today())->where('user_id', $this->student->id)->first();
        $this->assertSame('absent', $row->status);
    }

    public function test_a_future_approval_writes_nothing_until_that_day_closes(): void
    {
        $future = today()->addDays(3);
        $leave = $this->makeLeave(['from_date' => $future->toDateString(), 'to_date' => $future->toDateString()]);
        $this->review($leave, ['days' => [$future->toDateString()]]);

        // Closing today says nothing about a day that has not happened.
        $this->artisan('attendance:close-day')->assertSuccessful();
        $this->assertSame(0, DailyAttendance::onDate($future)->count());

        $this->artisan('attendance:close-day', ['--date' => $future->toDateString()])->assertSuccessful();
        $this->assertSame('leave', DailyAttendance::onDate($future)->where('user_id', $this->student->id)->value('status'));
    }

    public function test_closing_the_same_day_twice_changes_nothing(): void
    {
        $leave = $this->makeLeave(['from_date' => today()->toDateString(), 'to_date' => today()->toDateString()]);
        $this->review($leave, ['days' => [today()->toDateString()]]);

        $this->artisan('attendance:close-day')->assertSuccessful();
        $first = DailyAttendance::onDate(today())->where('user_id', $this->student->id)->first();

        $this->artisan('attendance:close-day')->assertSuccessful();
        $second = DailyAttendance::onDate(today())->where('user_id', $this->student->id)->first();

        $this->assertSame(1, DailyAttendance::onDate(today())->where('user_id', $this->student->id)->count());
        $this->assertSame($first->status, $second->status);
        $this->assertEquals($first->updated_at, $second->updated_at);
    }

    /* --------------------------- Monthly allowance -------------------------- */

    protected function apply(string $from, string $to, string $reason = 'Away'): TestResponse
    {
        Sanctum::actingAs($this->student);

        return $this->postJson('/api/v1/leaves', [
            'from_date' => $from,
            'to_date' => $to,
            'reason' => $reason,
        ]);
    }

    public function test_applying_opens_one_pending_day_row_per_day(): void
    {
        $this->apply(today()->addDay()->toDateString(), today()->addDays(3)->toDateString())
            ->assertCreated();

        $leave = LeaveApplication::first();
        $this->assertSame(3, $leave->decisions()->count());
        $this->assertSame(3, $leave->decisions()->where('status', 'pending')->count());
    }

    public function test_reviewing_updates_those_rows_rather_than_adding_more(): void
    {
        $this->apply(today()->addDay()->toDateString(), today()->addDays(3)->toDateString());
        $leave = LeaveApplication::first();

        $this->review($leave, [
            'days' => [today()->addDay()->toDateString(), today()->addDays(2)->toDateString()],
            'review_note' => 'The third clashes with the assessment.',
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, $leave->decisions()->count());
        $this->assertSame(2, $leave->decisions()->where('status', 'approved')->count());
        $this->assertSame(1, $leave->decisions()->where('status', 'declined')->count());
        $this->assertSame(0, $leave->decisions()->where('status', 'pending')->count());
    }

    public function test_an_unused_allowance_reports_nothing_spent(): void
    {
        $this->setAllowance(2);

        Sanctum::actingAs($this->student);
        $this->getJson('/api/v1/leaves')->assertOk()
            ->assertJsonPath('balance.allowance', 2)
            ->assertJsonPath('balance.used', 0)
            ->assertJsonPath('balance.remaining', 2);

        $this->apply(today()->addDay()->toDateString(), today()->addDay()->toDateString())
            ->assertCreated();
    }

    public function test_a_spent_allowance_refuses_the_next_application(): void
    {
        $this->setAllowance(2);
        $this->apply(today()->addDay()->toDateString(), today()->addDays(2)->toDateString())->assertCreated();
        $leave = LeaveApplication::first();
        $this->review($leave, ['days' => collect($leave->days())->map->toDateString()->all()]);

        $this->apply(today()->addDays(5)->toDateString(), today()->addDays(5)->toDateString())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from_date');

        $this->assertSame('No leave remaining in '.now()->format('F').'.',
            $this->apply(today()->addDays(6)->toDateString(), today()->addDays(6)->toDateString())
                ->json('errors.from_date.0'));

        $this->assertSame(0, $this->balance()['remaining']);
    }

    public function test_a_pending_day_holds_its_place_in_the_balance(): void
    {
        $this->setAllowance(2);
        $this->apply(today()->addDay()->toDateString(), today()->addDay()->toDateString())->assertCreated();

        // Reserved while it waits — not 2.
        $this->assertSame(1, $this->balance()['remaining']);
        $this->assertSame(1, $this->balance()['used']);
    }

    public function test_declining_a_day_gives_it_back(): void
    {
        $this->setAllowance(2);
        $this->apply(today()->addDay()->toDateString(), today()->addDay()->toDateString())->assertCreated();
        $this->assertSame(1, $this->balance()['remaining']);

        $leave = LeaveApplication::first();
        $this->review($leave, ['decline_all' => 1, 'review_note' => 'Not this time']);

        $this->assertSame(2, $this->balance()['remaining']);
        $this->assertSame(0, $this->balance()['used']);
    }

    public function test_a_range_longer_than_the_balance_is_refused_and_names_the_number(): void
    {
        $this->setAllowance(2);
        $this->apply(today()->addDay()->toDateString(), today()->addDay()->toDateString())->assertCreated();
        $leave = LeaveApplication::first();
        $this->review($leave, ['days' => [today()->addDay()->toDateString()]]);

        $this->assertSame(1, $this->balance()['remaining']);

        $response = $this->apply(today()->addDays(3)->toDateString(), today()->addDays(7)->toDateString())
            ->assertUnprocessable();

        $this->assertSame(
            'Only 1 leave remaining in '.now()->format('F').'.',
            $response->json('errors.from_date.0'),
        );

        $this->assertSame(1, LeaveApplication::count());
    }

    public function test_a_three_day_leave_spends_three_days(): void
    {
        $this->setAllowance(5);
        $this->apply(today()->addDay()->toDateString(), today()->addDays(3)->toDateString())->assertCreated();

        $this->assertSame(3, $this->balance()['used']);
        $this->assertSame(2, $this->balance()['remaining']);
    }

    public function test_a_range_crossing_a_month_is_split_between_the_two(): void
    {
        $this->setAllowance(3);
        // 28 Feb – 2 Mar: one day in February, two in March.
        $this->travelTo(Carbon::parse('2027-02-20 09:00'));

        $this->apply('2027-02-28', '2027-03-02')->assertCreated();

        $feb = $this->balance(month: Carbon::parse('2027-02-01'));
        $mar = $this->balance(month: Carbon::parse('2027-03-01'));

        $this->assertSame(1, $feb['used']);
        $this->assertSame(2, $feb['remaining']);
        $this->assertSame(2, $mar['used']);
        $this->assertSame(1, $mar['remaining']);
    }

    public function test_an_unused_month_never_adds_to_the_next(): void
    {
        $this->setAllowance(3);
        $this->travelTo(Carbon::parse('2027-02-10 09:00'));

        // February goes entirely unused.
        $this->assertSame(3, $this->balance(month: Carbon::parse('2027-02-01'))['remaining']);

        $this->travelTo(Carbon::parse('2027-03-10 09:00'));
        $this->assertSame(3, $this->balance()['remaining']);

        // And a four-day March request is still one too many.
        $this->apply('2027-03-15', '2027-03-18')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from_date');
    }

    public function test_a_crossing_range_is_refused_for_the_month_that_is_short(): void
    {
        $this->setAllowance(3);
        $this->travelTo(Carbon::parse('2027-02-20 09:00'));

        // March already has two days spoken for; February is untouched.
        $this->apply('2027-03-10', '2027-03-11')->assertCreated();

        // 28 Feb – 2 Mar needs 1 in Feb (fine) and 2 in March (only 1 left).
        $response = $this->apply('2027-02-28', '2027-03-02')->assertUnprocessable();

        $this->assertSame('Only 1 leave remaining in March.', $response->json('errors.from_date.0'));
    }

    public function test_a_pending_day_is_marked_absent_when_that_day_closes(): void
    {
        $this->setAllowance(5);
        $this->apply(today()->toDateString(), today()->toDateString())->assertCreated();

        // Nobody has ruled on it, so it is not leave — it is an unexplained
        // absence like any other unmarked day.
        $this->artisan('attendance:close-day')->assertSuccessful();

        $this->assertSame('absent', DailyAttendance::onDate(today())->where('user_id', $this->student->id)->value('status'));
    }

    public function test_the_allowance_cannot_be_saved_as_zero(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $this->actingAs($superAdmin)->put('/admin/settings', [
            'site_name' => 'MarkDev',
            'registration_fee' => 2000,
            'defaulter_fine_per_day' => 100,
            'billing_grace_days' => 5,
            'billing_activation_days' => 5,
            'attendance_day_start_hour' => 9,
            'attendance_day_start_minute' => 0,
            'attendance_day_start_meridiem' => 'AM',
            'attendance_late_after_minutes' => 15,
            // Required since holidays landed: an academy that never
            // opens marks nobody, so the form insists on a week.
            'academy_working_days' => [1, 2, 3, 4, 5],
            // Required since the holiday announcer landed: how far ahead a
            // closure is announced, minimum 1.
            'holiday_announce_days_before' => 1,
            // Required since the weights moved out of the constant.
            'attendance_weight_present' => 100,
            'attendance_weight_late' => 70,
            'attendance_weight_leave' => 50,
            'attendance_weight_absent' => 0,
            'monthly_leave_allowance' => 0,
            'attendance_mode' => AttendanceConfig::MODE_MANUAL,
        ])->assertSessionHasErrors(['monthly_leave_allowance' => 'Monthly leave allowance must be at least 1.']);

        Setting::forgetCached();
        $this->assertSame(5, LeaveAllowance::perMonth());
    }

    public function test_a_new_month_starts_the_balance_over(): void
    {
        $this->setAllowance(2);
        $this->travelTo(Carbon::parse('2027-04-10 09:00'));

        $this->apply('2027-04-20', '2027-04-21')->assertCreated();
        $this->assertSame(0, $this->balance()['remaining']);

        // The apply endpoint says so too, and says when it changes.
        Sanctum::actingAs($this->student);
        $this->getJson('/api/v1/leaves')->assertOk()
            ->assertJsonPath('balance.remaining', 0)
            ->assertJsonPath('balance.resets_on', '2027-05-01');

        $this->travelTo(Carbon::parse('2027-05-02 09:00'));
        $this->assertSame(2, $this->balance()['remaining']);
        $this->apply('2027-05-10', '2027-05-11')->assertCreated();
    }

    public function test_changing_the_setting_moves_the_balance_at_once(): void
    {
        $this->setAllowance(2);

        Sanctum::actingAs($this->student);
        $this->getJson('/api/v1/leaves')->assertOk()->assertJsonPath('balance.allowance', 2);

        $this->setAllowance(7);

        Sanctum::actingAs($this->student);
        $this->getJson('/api/v1/leaves')->assertOk()
            ->assertJsonPath('balance.allowance', 7)
            ->assertJsonPath('balance.remaining', 7);
    }

    /* ---------------------------- Attendance math -------------------------- */

    public function test_student_summary_rate_counts_leave_as_attended(): void
    {
        $this->attendanceRow(today()->subDays(2)->toDateString(), 'present');
        $this->attendanceRow(today()->subDay()->toDateString(), 'leave');
        $this->attendanceRow(today()->toDateString(), 'absent');

        // (present + late + leave) / total = 2/3 — leave no longer drags the rate down.
        $this->actingAs($this->admin)
            ->get('/admin/attendance/daily/'.$this->student->id.'?range=all')
            ->assertOk()
            ->assertSee('66.7');
    }

    public function test_dashboard_exposes_leave_stats(): void
    {
        $this->makeLeave(['status' => 'approved']); // covers today
        $this->makeLeave([
            'from_date' => today()->addDays(10)->toDateString(),
            'to_date' => today()->addDays(11)->toDateString(),
        ]); // pending

        Sanctum::actingAs($this->student);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.stats.approved_leave_today', true)
            ->assertJsonPath('data.stats.pending_leaves', 1);
    }
}
