<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceSlot;
use App\Models\Holiday;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationDay;
use App\Models\Setting;
use App\Models\User;
use App\Support\LeaveAllowance;
use Illuminate\Support\Carbon;

/**
 * What a leave request costs when the academy is shut for part of it.
 *
 * A day off is not leave from anything: the student would not have been marked
 * either way, so it must not spend an allowance they need for a day that
 * counts.
 */
class LeaveWorkingDaysTest extends ApiTestCase
{
    /** A Wednesday, so the Friday and Monday either side are unambiguous. */
    protected const WEDNESDAY = '2026-09-16';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::WEDNESDAY.' 08:00:00');
        $this->workingDays([1, 2, 3, 4, 5]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function workingDays(array $days): void
    {
        Setting::updateOrCreate(['key' => 'academy_working_days'], ['value' => $days, 'group' => 'general']);
        Setting::forgetCached();
    }

    protected function allowance(int $days): void
    {
        Setting::updateOrCreate(['key' => 'monthly_leave_allowance'], ['value' => $days, 'group' => 'general']);
        Setting::forgetCached();
    }

    protected function studentOn(?AttendanceSlot $slot = null): User
    {
        $student = $this->student();
        $student->studentProfile()->create([
            'reg_no' => 'MD-'.uniqid(),
            'attendance_slot_id' => $slot?->id,
        ]);

        return $this->actingAsStudent($student->fresh());
    }

    protected function apply(string $from, string $to, string $reason = 'Family event'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/leaves', [
            'from_date' => $from,
            'to_date' => $to,
            'reason' => $reason,
        ]);
    }

    public function test_a_friday_to_monday_request_over_a_weekend_costs_two_days(): void
    {
        $this->allowance(2);
        $student = $this->studentOn();

        // Fri 18th to Mon 21st September 2026 — four dates, two of them a
        // closed weekend.
        $this->apply('2026-09-18', '2026-09-21')->assertCreated();

        $this->assertSame(2, LeaveApplicationDay::count());
        $this->assertSame(
            ['2026-09-18', '2026-09-21'],
            LeaveApplicationDay::orderBy('date')->get()
                ->map(fn (LeaveApplicationDay $day) => Carbon::parse($day->date)->toDateString())->all(),
        );
        $this->assertSame(2, LeaveAllowance::usedIn($student->id, Carbon::parse(self::WEDNESDAY)));
    }

    public function test_that_request_would_not_fit_if_the_weekend_counted(): void
    {
        // The proof the assertion above is about the weekend and not about a
        // generous allowance: four chargeable days would be refused here.
        $this->allowance(2);
        $this->studentOn();

        $this->apply('2026-09-18', '2026-09-21')->assertCreated();

        $this->allowance(2);
        $this->workingDays([1, 2, 3, 4, 5, 6, 7]);
        LeaveApplication::query()->delete();
        LeaveApplicationDay::query()->delete();

        $this->apply('2026-09-18', '2026-09-21')
            ->assertStatus(422)
            ->assertJsonValidationErrors('from_date');
    }

    public function test_a_request_sitting_entirely_on_a_holiday_costs_nothing(): void
    {
        $this->allowance(2);
        $student = $this->studentOn();

        Holiday::create(['date' => '2026-09-17', 'name' => 'Eid ul-Fitr']);
        Holiday::create(['date' => '2026-09-18', 'name' => 'Eid ul-Fitr']);

        $this->apply('2026-09-17', '2026-09-18')->assertCreated();

        $this->assertSame(0, LeaveApplicationDay::count());
        $this->assertSame(0, LeaveAllowance::usedIn($student->id, Carbon::parse(self::WEDNESDAY)));
    }

    public function test_a_holiday_inside_a_range_is_not_charged_for(): void
    {
        $this->allowance(3);
        $student = $this->studentOn();

        Holiday::create(['date' => '2026-09-17', 'name' => 'Eid ul-Fitr']);

        // Wed 16th to Fri 18th: three weekdays, one of them a holiday.
        $this->apply('2026-09-16', '2026-09-18')->assertCreated();

        $this->assertSame(2, LeaveAllowance::usedIn($student->id, Carbon::parse(self::WEDNESDAY)));
        $this->assertSame(
            ['2026-09-16', '2026-09-18'],
            LeaveApplicationDay::orderBy('date')->get()
                ->map(fn (LeaveApplicationDay $day) => Carbon::parse($day->date)->toDateString())->all(),
        );
    }

    public function test_a_student_on_a_saturday_slot_spends_a_saturday(): void
    {
        // The academy is Mon–Fri, but this student's slot runs Sat and Sun, so
        // those are exactly the days leave costs them.
        $this->allowance(3);
        $slot = AttendanceSlot::create([
            'name' => 'Weekend '.uniqid(),
            'start_time' => '09:00:00',
            'end_time' => '13:00:00',
            'days' => [6, 7],
            'late_after_minutes' => 15,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $student = $this->studentOn($slot);

        // Fri 18th to Mon 21st again — this time only the weekend counts.
        $this->apply('2026-09-18', '2026-09-21')->assertCreated();

        $this->assertSame(
            ['2026-09-19', '2026-09-20'],
            LeaveApplicationDay::orderBy('date')->get()
                ->map(fn (LeaveApplicationDay $day) => Carbon::parse($day->date)->toDateString())->all(),
        );
        $this->assertSame(2, LeaveAllowance::usedIn($student->id, Carbon::parse(self::WEDNESDAY)));
    }

    public function test_the_allowance_balance_the_portal_reads_agrees_with_what_was_written(): void
    {
        $this->allowance(2);
        $student = $this->studentOn();

        $this->apply('2026-09-18', '2026-09-21')->assertCreated();

        $balance = $this->getJson('/api/v1/leaves')->assertOk()->json('balance');

        $this->assertSame(2, $balance['used']);
        $this->assertSame(0, $balance['remaining']);
        $this->assertSame(2, LeaveAllowance::usedIn($student->id, Carbon::parse(self::WEDNESDAY)));
    }
}
