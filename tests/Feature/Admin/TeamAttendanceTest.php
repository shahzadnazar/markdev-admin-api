<?php

namespace Tests\Feature\Admin;

use App\Models\Holiday;
use App\Models\Setting;
use App\Models\Team;
use App\Models\TeamAttendance;
use App\Models\User;
use App\Support\TeamAttendanceConfig;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * The team register: the late rule, who may mark, and the absent lock.
 */
class TeamAttendanceTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected User $admin;

    protected User $lead;

    protected User $member;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();

        $this->admin = $this->roleUser('super-admin');
        $this->lead = $this->roleUser('team-lead', ['name' => 'Lead Person']);
        $this->member = $this->roleUser('team', ['name' => 'Member Person']);
        $this->team = $this->makeTeam('Web', $this->lead, [$this->lead, $this->member]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* -------------------------------- Lateness ------------------------------ */

    public function test_late_is_office_start_plus_the_grace(): void
    {
        Setting::updateOrCreate(['key' => TeamAttendanceConfig::START_KEY], ['value' => '09:00', 'group' => 'general']);
        Setting::updateOrCreate(['key' => TeamAttendanceConfig::GRACE_KEY], ['value' => 15, 'group' => 'general']);
        Setting::forgetCached();

        $day = $this->monday();

        $this->assertSame('present', TeamAttendanceConfig::statusForArrival($day->copy()->setTime(8, 55)));
        $this->assertSame('present', TeamAttendanceConfig::statusForArrival($day->copy()->setTime(9, 0)));

        // The LAST minute of the grace is still present. A grace that excluded
        // its own final minute would be a grace one minute shorter than the
        // number in the form.
        $this->assertSame('present', TeamAttendanceConfig::statusForArrival($day->copy()->setTime(9, 15)));

        $this->assertSame('late', TeamAttendanceConfig::statusForArrival($day->copy()->setTime(9, 16)));
    }

    public function test_the_office_start_is_shown_in_twelve_hour_time(): void
    {
        Setting::updateOrCreate(['key' => TeamAttendanceConfig::START_KEY], ['value' => '13:30', 'group' => 'general']);
        Setting::forgetCached();

        $this->assertSame('13:30', TeamAttendanceConfig::officeStart());
        $this->assertSame('1:30 PM', TeamAttendanceConfig::officeStartLabel());
    }

    /* ------------------------------- Who marks ------------------------------ */

    public function test_a_lead_marks_their_own_team(): void
    {
        $this->actingAs($this->lead)
            ->post(route('admin.team-attendance.store'), [
                'team' => $this->team->id,
                'date' => $this->monday()->toDateString(),
                'status' => [$this->member->id => 'present'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('present', TeamAttendance::where('user_id', $this->member->id)->firstOrFail()->status);
    }

    public function test_a_lead_is_refused_for_a_team_they_are_not_on(): void
    {
        $other = $this->makeTeam('Graphics');
        $stranger = $this->roleUser('team');
        $other->members()->attach($stranger->id);

        // 404, not 403: the same answer a project or a task outside somebody's
        // scope gives, so the refusal does not confirm the team's membership.
        $this->actingAs($this->lead)
            ->post(route('admin.team-attendance.store'), [
                'team' => $other->id,
                'date' => $this->monday()->toDateString(),
                'status' => [$stranger->id => 'present'],
            ])
            ->assertNotFound();

        $this->assertSame(0, TeamAttendance::count());
    }

    public function test_a_member_cannot_reach_the_marking_screen(): void
    {
        $this->actingAs($this->member)->get(route('admin.team-attendance.index'))->assertForbidden();
        $this->actingAs($this->member)->post(route('admin.team-attendance.store'), [])->assertForbidden();
    }

    /* ------------------------------ The absent lock ------------------------- */

    /**
     * The case the academy leaked twice.
     *
     * A lead who marked somebody absent cannot unmark them — not through the
     * screen, and not through the model either. Only a holder of
     * `attendance.correct-absent` may, and no team role holds it.
     */
    public function test_a_lead_cannot_undo_an_absent_they_marked_themselves(): void
    {
        $this->actingAs($this->lead)
            ->post(route('admin.team-attendance.store'), [
                'team' => $this->team->id,
                'date' => $this->monday()->toDateString(),
                'status' => [$this->member->id => 'absent'],
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->lead)
            ->post(route('admin.team-attendance.store'), [
                'team' => $this->team->id,
                'date' => $this->monday()->toDateString(),
                'status' => [$this->member->id => 'present'],
            ]);

        $this->assertSame('absent', TeamAttendance::where('user_id', $this->member->id)->firstOrFail()->status);

        // And straight past the controller: the rule is on the MODEL, which is
        // what stops the next call site forgetting it.
        $this->actingAs($this->lead);
        $record = TeamAttendance::where('user_id', $this->member->id)->firstOrFail();

        $this->expectException(AuthorizationException::class);
        $record->update(['status' => 'present']);
    }

    public function test_an_admin_may_undo_an_absent(): void
    {
        $this->actingAs($this->lead)->post(route('admin.team-attendance.store'), [
            'team' => $this->team->id,
            'date' => $this->monday()->toDateString(),
            'status' => [$this->member->id => 'absent'],
        ]);

        $this->actingAs($this->admin)->post(route('admin.team-attendance.store'), [
            'team' => $this->team->id,
            'date' => $this->monday()->toDateString(),
            'status' => [$this->member->id => 'present'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('present', TeamAttendance::where('user_id', $this->member->id)->firstOrFail()->status);
    }

    /* ------------------------------- The close ------------------------------ */

    public function test_the_close_marks_absent_on_a_working_day(): void
    {
        $this->artisan('attendance:close-day', ['--date' => $this->monday()->toDateString()])->assertSuccessful();

        $this->assertSame('absent', TeamAttendance::where('user_id', $this->member->id)->firstOrFail()->status);
        $this->assertSame('system', TeamAttendance::where('user_id', $this->member->id)->firstOrFail()->source);
    }

    public function test_the_close_skips_weekends(): void
    {
        // The Saturday after the anchor Monday.
        $this->artisan('attendance:close-day', ['--date' => $this->monday()->copy()->addDays(5)->toDateString()])
            ->assertSuccessful();

        $this->assertSame(0, TeamAttendance::count());
    }

    public function test_the_close_marks_a_holiday_rather_than_an_absence(): void
    {
        Holiday::create(['date' => $this->monday()->toDateString(), 'name' => 'Founders Day']);

        $this->artisan('attendance:close-day', ['--date' => $this->monday()->toDateString()])->assertSuccessful();

        // A row, because the date is not obvious and a gap would read as
        // missing data — but a holiday is not attendance and never counts.
        $this->assertSame(TeamAttendance::HOLIDAY, TeamAttendance::where('user_id', $this->member->id)->firstOrFail()->status);
    }

    public function test_the_close_does_not_overwrite_an_existing_mark(): void
    {
        $this->actingAs($this->lead)->post(route('admin.team-attendance.store'), [
            'team' => $this->team->id,
            'date' => $this->monday()->toDateString(),
            'status' => [$this->member->id => 'present'],
        ]);

        $this->artisan('attendance:close-day', ['--date' => $this->monday()->toDateString()])->assertSuccessful();

        $this->assertSame('present', TeamAttendance::where('user_id', $this->member->id)->firstOrFail()->status);
    }

    public function test_the_close_records_approved_leave_rather_than_an_absence(): void
    {
        $this->approveLeaveFor($this->member, $this->monday(), $this->monday());

        $this->artisan('attendance:close-day', ['--date' => $this->monday()->toDateString()])->assertSuccessful();

        $this->assertSame('leave', TeamAttendance::where('user_id', $this->member->id)->firstOrFail()->status);
        // The lead had no leave, so they are simply absent.
        $this->assertSame('absent', TeamAttendance::where('user_id', $this->lead->id)->firstOrFail()->status);
    }

    public function test_the_close_leaves_students_out_of_the_team_table(): void
    {
        $student = $this->roleUser('student');

        $this->artisan('attendance:close-day', ['--date' => $this->monday()->toDateString()])->assertSuccessful();

        // A separate table means a separate population — which is the whole
        // reason it is separate.
        $this->assertSame(0, TeamAttendance::where('user_id', $student->id)->count());
    }

    /**
     * No mass update on the team register slips past the model lock.
     *
     * The mirror of the academy's sweep, for the new table. A query-builder
     * update fires no model events, so LocksAbsences cannot see it; the close
     * only INSERTS rows for days with no record at all, which is why the list
     * below is empty rather than naming it. This fails the day a builder update
     * appears, so the gap stays known instead of becoming the next forgotten
     * call site — which is exactly how the academy lost this rule twice.
     */
    public function test_no_mass_update_slips_past_the_team_model_guard(): void
    {
        $known = [];
        $found = [];

        foreach ((new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()))) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Comments stripped first: this test and the trait both describe
            // the pattern in prose, and a guard that trips on its own
            // explanation is a guard people delete.
            $code = preg_replace(
                ['#/\*[\s\S]*?\*/#', '#^\s*//.*$#m'],
                ' ',
                file_get_contents($file->getPathname()),
            );

            $builderWrite = '/(TeamAttendance::|DB::table\([\'"]team_attendance_records[\'"]\))[^;]{0,400}->update\(/s';

            if (preg_match($builderWrite, $code)) {
                $found[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        sort($found);
        $this->assertSame(
            $known,
            $found,
            'A mass update on team_attendance_records bypasses the model lock. '
                .'Either write through a model instance, or check mayUndoAbsence() first and add the file here.',
        );
    }

    /* --------------------------------- Mine --------------------------------- */

    public function test_a_member_sees_their_own_month(): void
    {
        TeamAttendance::create([
            'user_id' => $this->member->id,
            'date' => TeamAttendance::dayKey($this->monday()),
            'status' => 'late',
        ]);

        $this->actingAs($this->member)->get(route('admin.team-attendance.mine'))
            ->assertOk()
            ->assertSee('Late');
    }
}
