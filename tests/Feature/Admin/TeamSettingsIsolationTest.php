<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\AbsenceFine;
use App\Support\AttendanceConfig;
use App\Support\LeaveAllowance;
use App\Support\TeamAttendanceConfig;
use App\Support\TeamFineRules;
use App\Support\TeamLeaveAllowance;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSettingsPayload;
use Tests\TestCase;

/**
 * A student number never moves a team number, and the reverse.
 *
 * Five team settings landed beside five older academy ones with very similar
 * names, and the academy's are the ones already in everybody's head. This is
 * the test that notices the day somebody reads the wrong key — asserted in BOTH
 * directions, because either mistake is equally easy and only one of them would
 * be caught by a test that checked the other.
 */
class TeamSettingsIsolationTest extends TestCase
{
    use BuildsSettingsPayload, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    protected function save(array $overrides = [])
    {
        return $this->actingAs($this->admin)
            ->from(route('admin.settings.edit'))
            ->put(route('admin.settings.update'), $this->settingsPayload($overrides));
    }

    public function test_moving_every_academy_number_leaves_the_team_numbers_alone(): void
    {
        $this->save([
            'attendance_day_start_hour' => 7,
            'attendance_day_start_minute' => 30,
            'attendance_day_start_meridiem' => 'AM',
            'attendance_late_after_minutes' => 45,
            'monthly_leave_allowance' => 11,
            'monthly_absent_allowance' => 12,
            'absent_fine_amount' => 4321,
        ])->assertSessionHasNoErrors();

        // The academy moved…
        $this->assertSame('07:30', AttendanceConfig::dayStart());
        $this->assertSame(45, AttendanceConfig::lateAfterMinutes());
        $this->assertSame(11, LeaveAllowance::perMonth());
        $this->assertSame(12, AbsenceFine::allowance());
        $this->assertSame(4321.0, (float) AbsenceFine::perAbsence());

        // …and the team did not.
        $this->assertSame('09:00', TeamAttendanceConfig::officeStart());
        $this->assertSame(TeamAttendanceConfig::DEFAULT_GRACE, TeamAttendanceConfig::lateAfterMinutes());
        $this->assertSame(TeamLeaveAllowance::DEFAULT_PER_MONTH, TeamLeaveAllowance::perMonth());
        $this->assertSame(TeamFineRules::DEFAULT_ALLOWANCE, TeamFineRules::allowance());
        $this->assertSame(TeamFineRules::DEFAULT_RATE, TeamFineRules::perAbsence());
    }

    public function test_moving_every_team_number_leaves_the_academy_numbers_alone(): void
    {
        $this->save([
            'team_office_start_hour' => 11,
            'team_office_start_minute' => 45,
            'team_office_start_meridiem' => 'AM',
            'team_late_after_minutes' => 5,
            'team_leave_allowance_per_month' => 7,
            'team_absent_allowance_per_month' => 8,
            'team_absent_fine_amount' => 1234,
        ])->assertSessionHasNoErrors();

        // The team moved…
        $this->assertSame('11:45', TeamAttendanceConfig::officeStart());
        $this->assertSame('11:45 AM', TeamAttendanceConfig::officeStartLabel());
        $this->assertSame(5, TeamAttendanceConfig::lateAfterMinutes());
        $this->assertSame(7, TeamLeaveAllowance::perMonth());
        $this->assertSame(8, TeamFineRules::allowance());
        $this->assertSame(1234.0, TeamFineRules::perAbsence());

        // …and the academy did not. These are the payload defaults.
        $this->assertSame('09:00', AttendanceConfig::dayStart());
        $this->assertSame(15, AttendanceConfig::lateAfterMinutes());
        $this->assertSame(2, LeaveAllowance::perMonth());
        $this->assertSame(2, AbsenceFine::allowance());
        $this->assertSame(500.0, (float) AbsenceFine::perAbsence());
    }

    public function test_the_team_office_start_is_refused_without_its_parts(): void
    {
        $payload = $this->settingsPayload();
        unset($payload['team_office_start_hour']);

        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), $payload)
            ->assertSessionHasErrors('team_office_start_hour');
    }

    public function test_a_team_allowance_of_zero_is_refused_by_name(): void
    {
        $this->save(['team_leave_allowance_per_month' => 0])
            ->assertSessionHasErrors('team_leave_allowance_per_month');

        $this->assertStringContainsString('at least 1', session('errors')->first('team_leave_allowance_per_month'));
    }

    /** Zero IS meaningful for the rate: tracked, never charged. */
    public function test_a_team_fine_of_zero_is_allowed(): void
    {
        $this->save(['team_absent_fine_amount' => 0])->assertSessionHasNoErrors();

        $this->assertSame(0.0, TeamFineRules::perAbsence());
    }
}
