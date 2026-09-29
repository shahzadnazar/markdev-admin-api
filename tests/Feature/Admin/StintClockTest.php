<?php

namespace Tests\Feature\Admin;

use App\Models\Holiday;
use App\Models\ProjectStatus;
use App\Models\TaskStatus;
use App\Models\User;
use App\Services\StintClock;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTeamPortal;
use Tests\TestCase;

/**
 * What a stint is charged for, and what it is not.
 *
 * FOUR EXCLUSIONS, ONE TEST EACH, on purpose. A single test that removed a
 * weekend, a holiday, a blocked day and a paused day at once could be satisfied
 * by a fix that only handled one of them, and nobody would know which.
 *
 * Every date is anchored to a known Monday, so a weekend exclusion is a fact
 * about the calendar rather than about the day the suite happened to run.
 */
class StintClockTest extends TestCase
{
    use BuildsTeamPortal, RefreshDatabase;

    protected StintClock $clock;

    protected User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeOnMonday();

        $this->clock = app(StintClock::class);
        $this->member = $this->roleUser('team');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------- Baseline ------------------------------- */

    public function test_a_plain_week_is_five_working_days(): void
    {
        $team = $this->makeTeam('Web', null, [$this->member]);
        $task = $this->makeTask($team);
        $stint = $this->makeStint($task, $this->member, 5, $this->monday(), $this->monday()->copy()->addDays(4));

        $this->assertSame(5, $this->clock->daysTaken($stint));
        $this->assertSame('on_time', $this->clock->outcomeFor($stint));
    }

    /* ------------------------------ Exclusion 1 ----------------------------- */

    public function test_weekends_are_excluded(): void
    {
        $team = $this->makeTeam('Web', null, [$this->member]);
        $task = $this->makeTask($team);

        // Monday to the following Monday: eight calendar days, six working
        // ones. A clock counting calendar days would say eight.
        $stint = $this->makeStint($task, $this->member, 6, $this->monday(), $this->monday()->copy()->addDays(7));

        $this->assertSame(6, $this->clock->daysTaken($stint));
        $this->assertSame('on_time', $this->clock->outcomeFor($stint));
    }

    /* ------------------------------ Exclusion 2 ----------------------------- */

    public function test_holidays_are_excluded(): void
    {
        $team = $this->makeTeam('Web', null, [$this->member]);
        $task = $this->makeTask($team);

        Holiday::create(['date' => $this->monday()->copy()->addDays(2)->toDateString(), 'name' => 'Founders Day']);

        $stint = $this->makeStint($task, $this->member, 5, $this->monday(), $this->monday()->copy()->addDays(4));

        // Five weekdays, one of them shut.
        $this->assertSame(4, $this->clock->daysTaken($stint));
        $this->assertSame('early', $this->clock->outcomeFor($stint));
    }

    /* ------------------------------ Exclusion 3 ----------------------------- */

    public function test_blocked_days_are_excluded_and_counted(): void
    {
        $team = $this->makeTeam('Web', null, [$this->member]);
        $task = $this->makeTask($team);

        // Tuesday and Wednesday parked on a blocked status.
        $this->blockTask($task, $this->monday()->copy()->addDay(), $this->monday()->copy()->addDays(2));

        $stint = $this->makeStint($task, $this->member, 5, $this->monday(), $this->monday()->copy()->addDays(4));

        $this->assertSame(3, $this->clock->daysTaken($stint));

        // Counted as well as subtracted. Anyone can stop their own clock; the
        // answer is that the parking is visible, not that it is forbidden.
        $this->assertSame(2, $this->clock->blockedDays($stint));
    }

    /* ------------------------------ Exclusion 4 ----------------------------- */

    public function test_paused_project_days_are_excluded(): void
    {
        $team = $this->makeTeam('Web', null, [$this->member]);
        $project = $this->makeProject($team);
        $task = $this->makeTask($team, ['project_id' => $project->id]);

        $this->pauseProject($project, $this->monday()->copy()->addDay(), $this->monday()->copy()->addDays(2));

        $stint = $this->makeStint($task, $this->member, 5, $this->monday(), $this->monday()->copy()->addDays(4));

        $this->assertSame(3, $this->clock->daysTaken($stint));
        $this->assertSame(2, $this->clock->pausedDays($stint));
        // Paused is not blocked; the two are counted separately.
        $this->assertSame(0, $this->clock->blockedDays($stint));
    }

    public function test_a_day_that_is_both_blocked_and_paused_is_subtracted_once(): void
    {
        $team = $this->makeTeam('Web', null, [$this->member]);
        $project = $this->makeProject($team);
        $task = $this->makeTask($team, ['project_id' => $project->id]);

        $tuesday = $this->monday()->copy()->addDay();
        $this->blockTask($task, $tuesday, $tuesday);
        $this->pauseProject($project, $tuesday, $tuesday);

        $stint = $this->makeStint($task, $this->member, 5, $this->monday(), $this->monday()->copy()->addDays(4));

        // Four, not three: subtracting two counts would have made a paused
        // fortnight on a blocked task read as a month of credit.
        $this->assertSame(4, $this->clock->daysTaken($stint));
    }

    /* ------------------------------ Exclusion 5 ----------------------------- */

    public function test_approved_leave_days_are_excluded(): void
    {
        $team = $this->makeTeam('Web', null, [$this->member]);
        $task = $this->makeTask($team);

        // Tuesday and Wednesday off, agreed in advance.
        $this->approveLeaveFor($this->member, $this->monday()->copy()->addDay(), $this->monday()->copy()->addDays(2));

        $stint = $this->makeStint($task, $this->member, 5, $this->monday(), $this->monday()->copy()->addDays(4));

        $this->assertSame(3, $this->clock->daysTaken($stint));
    }

    public function test_leave_is_per_person_not_per_task(): void
    {
        $team = $this->makeTeam('Web', null, [$this->member]);
        $task = $this->makeTask($team);
        $somebodyElse = $this->roleUser('team');

        // Somebody else's leave, on the same task. Blocked and paused are facts
        // about the work; leave is a fact about who was doing it.
        $this->approveLeaveFor($somebodyElse, $this->monday()->copy()->addDay(), $this->monday()->copy()->addDays(2));

        $stint = $this->makeStint($task, $this->member, 5, $this->monday(), $this->monday()->copy()->addDays(4));

        $this->assertSame(5, $this->clock->daysTaken($stint));
    }

    public function test_pending_leave_does_not_stop_the_clock(): void
    {
        $team = $this->makeTeam('Web', null, [$this->member]);
        $task = $this->makeTask($team);

        $leave = \App\Models\TeamLeaveApplication::create([
            'user_id' => $this->member->id,
            'from_date' => \App\Models\TeamLeaveApplication::dayKey($this->monday()->copy()->addDay()),
            'to_date' => \App\Models\TeamLeaveApplication::dayKey($this->monday()->copy()->addDays(2)),
            'reason' => 'Asked, not answered',
        ]);
        $leave->openDecisions();

        $stint = $this->makeStint($task, $this->member, 5, $this->monday(), $this->monday()->copy()->addDays(4));

        // Not agreed yet, so it has stopped nothing.
        $this->assertSame(5, $this->clock->daysTaken($stint));
    }

    /**
     * Absence is never free.
     *
     * Being away without leave is exactly what the score should notice.
     * Excluding it would also mean somebody could buy their way out of a late
     * delivery, since an absence is what a fine is charged on.
     */
    public function test_absent_days_are_not_excluded(): void
    {
        $team = $this->makeTeam('Web', null, [$this->member]);
        $task = $this->makeTask($team);

        foreach ([1, 2] as $offset) {
            \App\Models\TeamAttendance::create([
                'user_id' => $this->member->id,
                'date' => \App\Models\TeamAttendance::dayKey($this->monday()->copy()->addDays($offset)),
                'status' => 'absent',
            ]);
        }

        $stint = $this->makeStint($task, $this->member, 5, $this->monday(), $this->monday()->copy()->addDays(4));

        $this->assertSame(5, $this->clock->daysTaken($stint));
    }

    public function test_a_day_that_is_both_blocked_and_on_leave_costs_one_day(): void
    {
        $team = $this->makeTeam('Web', null, [$this->member]);
        $task = $this->makeTask($team);

        $tuesday = $this->monday()->copy()->addDay();
        $this->blockTask($task, $tuesday, $tuesday);
        $this->approveLeaveFor($this->member, $tuesday, $tuesday);

        $stint = $this->makeStint($task, $this->member, 5, $this->monday(), $this->monday()->copy()->addDays(4));

        // Four, not three: the sources are unioned before counting, so one day
        // costs one day of credit however many reasons it had.
        $this->assertSame(4, $this->clock->daysTaken($stint));
    }

    /* --------------------------- Behaviour, not label ----------------------- */

    /**
     * Renaming every status changes no figure.
     *
     * The single most important property of the whole scheme: an admin owns
     * the wording and the code owns the meaning, and the two never touch.
     */
    public function test_renaming_every_status_label_changes_nothing(): void
    {
        $team = $this->makeTeam('Web', null, [$this->member]);
        $project = $this->makeProject($team);
        $task = $this->makeTask($team, ['project_id' => $project->id]);

        $this->blockTask($task, $this->monday()->copy()->addDay(), $this->monday()->copy()->addDay());
        $this->pauseProject($project, $this->monday()->copy()->addDays(2), $this->monday()->copy()->addDays(2));

        $stint = $this->makeStint($task, $this->member, 5, $this->monday(), $this->monday()->copy()->addDays(4));

        $before = [
            'taken' => $this->clock->daysTaken($stint),
            'blocked' => $this->clock->blockedDays($stint),
            'paused' => $this->clock->pausedDays($stint),
            'outcome' => $this->clock->outcomeFor($stint),
        ];

        foreach (TaskStatus::all() as $status) {
            $status->update(['label' => 'Renamed '.$status->id]);
        }

        foreach (ProjectStatus::all() as $status) {
            $status->update(['label' => 'Also renamed '.$status->id]);
        }

        $after = [
            'taken' => $this->clock->daysTaken($stint->fresh()),
            'blocked' => $this->clock->blockedDays($stint->fresh()),
            'paused' => $this->clock->pausedDays($stint->fresh()),
            'outcome' => $this->clock->outcomeFor($stint->fresh()),
        ];

        $this->assertSame($before, $after);
        $this->assertSame(3, $after['taken']);
    }

    /* -------------------------------- Outcomes ------------------------------ */

    public function test_the_outcome_is_judged_against_the_stints_own_allowance(): void
    {
        $team = $this->makeTeam('Web', null, [$this->member]);
        $task = $this->makeTask($team, ['days_allowed' => 20]);

        // The task was given twenty days. This person was given two, and took
        // five. They are late — the parent's promise is not theirs.
        $stint = $this->makeStint($task, $this->member, 2, $this->monday(), $this->monday()->copy()->addDays(4));

        $this->assertSame('late', $this->clock->outcomeFor($stint));
        $this->assertSame(3, $this->clock->daysOver($stint));
    }

    public function test_an_open_stint_is_measured_to_today(): void
    {
        $team = $this->makeTeam('Web', null, [$this->member]);
        $task = $this->makeTask($team);

        Carbon::setTestNow($this->monday()->copy()->addDays(2));

        $stint = $this->makeStint($task, $this->member, 5, $this->monday());

        // Monday to Wednesday, still open. Overdue work is visible before it
        // finishes, not after.
        $this->assertSame(3, $this->clock->daysTaken($stint));
    }
}
