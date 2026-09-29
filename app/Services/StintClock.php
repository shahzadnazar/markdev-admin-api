<?php

namespace App\Services;

use App\Models\ProjectStatusPeriod;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskStatusPeriod;
use App\Models\TeamLeaveApplicationDay;
use App\Support\AcademyCalendar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How many days a stint actually took, and how many of them were parked.
 *
 * ## What counts
 *
 * Working days only, decided by AcademyCalendar — the one calendar this system
 * has. Weekends the academy is shut and holidays are never a person's fault.
 *
 * ## What is excluded, and why each one
 *
 *   - non-working weekdays and holidays: nobody was asked to work
 *   - days the task sat on a BLOCKED status: waiting on someone else
 *   - days its project sat on a PAUSED status: the client stopped the work
 *   - days the stint's ASSIGNEE was on APPROVED leave: they were not at work,
 *     and it was agreed in advance
 *
 * ABSENT DAYS KEEP COUNTING. Being away without leave is exactly what the score
 * should notice, and excluding it would make absence free — which, since an
 * absence is also what a fine is charged on, would let somebody buy their way
 * out of a late delivery. Leave is per PERSON, unlike the other two, so it is
 * taken from the stint's user rather than from its task.
 *
 * Both exclusions are decided on the status BEHAVIOUR. Never on the label —
 * an admin renaming "Blocked" to "Waiting on client", or "On Hold" to anything
 * at all, must not move a single figure, and there is a test that says so.
 *
 * The two sets are UNIONED before counting, so a day that is both blocked and
 * paused is subtracted once. Subtracting two counts would have made a paused
 * fortnight on a blocked task read as a month of credit.
 *
 * ## Blocked days are counted as well as subtracted
 *
 * Anyone can stop their own clock by parking a task in Blocked. The answer is
 * not to forbid it — a member is the first to know they are stuck — but to
 * make it visible: every blocked day is counted, shown per stint and totalled
 * per person beside their score, and setting a blocked status takes a written
 * reason that is stored and audited.
 */
class StintClock
{
    /**
     * Primed lookups, so a person's whole record costs a fixed number of
     * queries rather than one per stint.
     *
     * Without this the calculator ran a blocked-periods query, a paused-periods
     * query and a holidays query for every stint somebody had ever held — the
     * same N+1 the course-progress work was written to avoid, and a test that
     * counts queries is what caught it here too.
     *
     * Null means "not primed": every lookup falls back to its own query, which
     * is right for a single stint on a page.
     *
     * @var array<int, array<int, array{started_on: string, ended_on: ?string}>>|null
     */
    protected ?array $blockedByTask = null;

    /** @var array<int, array<int, array{started_on: string, ended_on: ?string}>>|null */
    protected ?array $pausedByProject = null;

    /**
     * user id => approved leave dates, as Y-m-d.
     *
     * @var array<int, array<int, string>>|null
     */
    protected ?array $leaveByUser = null;

    /** @var Collection<string, string>|null */
    protected ?Collection $holidays = null;

    /**
     * Load everything a set of stints will ask for, in three queries.
     *
     * @param  iterable<int, TaskAssignment>  $stints
     */
    public function primeFor(iterable $stints): void
    {
        $stints = collect($stints);

        if ($stints->isEmpty()) {
            $this->blockedByTask = [];
            $this->pausedByProject = [];
            $this->leaveByUser = [];

            return;
        }

        $taskIds = $stints->pluck('task_id')->filter()->unique()->values();
        $projectIds = $stints->pluck('task.project_id')->filter()->unique()->values();

        $from = $stints->min(fn (TaskAssignment $stint) => $stint->started_on?->toDateString()) ?? today()->toDateString();
        $to = Carbon::today()->toDateString();

        $this->holidays = AcademyCalendar::holidayMap($from, $to);

        $this->blockedByTask = TaskStatusPeriod::query()
            ->whereIn('task_id', $taskIds)
            ->behaving('blocked')
            ->get(['task_id', 'started_on', 'ended_on'])
            ->groupBy('task_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => [
                'started_on' => Carbon::parse($row->started_on)->toDateString(),
                'ended_on' => $row->ended_on === null ? null : Carbon::parse($row->ended_on)->toDateString(),
            ])->all())
            ->all();

        // ONE query for every approved leave day in the window, for everybody
        // in the set. The query-count test is what keeps this honest: it caught
        // the last N+1 here, and adding a fourth per-stint query would have
        // been the same mistake wearing a different hat.
        $this->leaveByUser = TeamLeaveApplicationDay::query()
            // JOINED rather than eager-loaded on purpose. `with` would be a
            // second query the moment there is any leave to load, and the
            // budget for this source is one.
            ->join('team_leave_applications', 'team_leave_applications.id', '=', 'team_leave_application_days.team_leave_application_id')
            ->where('team_leave_application_days.status', TeamLeaveApplicationDay::APPROVED)
            ->whereIn('team_leave_applications.user_id', $stints->pluck('user_id')->filter()->unique()->values())
            // Through the sanctioned scope, with the column named explicitly
            // because the join makes `date` ambiguous.
            ->betweenDates($from, $to, 'team_leave_application_days.date')
            ->get(['team_leave_applications.user_id', 'team_leave_application_days.date'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => Carbon::parse($row->date)->toDateString())->all())
            ->all();

        $this->pausedByProject = ProjectStatusPeriod::query()
            ->whereIn('project_id', $projectIds)
            ->behaving('paused')
            ->get(['project_id', 'started_on', 'ended_on'])
            ->groupBy('project_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => [
                'started_on' => Carbon::parse($row->started_on)->toDateString(),
                'ended_on' => $row->ended_on === null ? null : Carbon::parse($row->ended_on)->toDateString(),
            ])->all())
            ->all();
    }

    /**
     * Working days spent on this stint, excluding everything above.
     *
     * An open stint is measured to today, which is what makes an overdue task
     * visible before it is finished rather than after.
     */
    public function daysTaken(TaskAssignment $stint): int
    {
        [$from, $to] = $this->window($stint);

        if ($from === null) {
            return 0;
        }

        return AcademyCalendar::workingDaysBetween(
            $from,
            $to,
            $this->holidays,
            $this->excludedDates($stint),
        );
    }

    /** Working days this stint spent parked on a blocked status. */
    public function blockedDays(TaskAssignment $stint): int
    {
        [$from, $to] = $this->window($stint);

        if ($from === null) {
            return 0;
        }

        return count(array_intersect(
            AcademyCalendar::workingDatesBetween($from, $to, $this->holidays),
            $this->blockedDates($stint->task, $from, $to),
        ));
    }

    /** Working days this stint spent waiting on a paused project. */
    public function pausedDays(TaskAssignment $stint): int
    {
        [$from, $to] = $this->window($stint);

        if ($from === null) {
            return 0;
        }

        return count(array_intersect(
            AcademyCalendar::workingDatesBetween($from, $to, $this->holidays),
            $this->pausedDates($stint->task, $from, $to),
        ));
    }

    /**
     * How the stint ended, against ITS OWN allowance.
     *
     * Never against the parent task's — that is the admin's promise about the
     * whole job, and a member who was given three days is answerable for three
     * days whatever the parent says.
     */
    public function outcomeFor(TaskAssignment $stint): string
    {
        $taken = $this->daysTaken($stint);
        $allowed = (int) $stint->days_allowed;

        return match (true) {
            $taken < $allowed => 'early',
            $taken === $allowed => 'on_time',
            default => 'late',
        };
    }

    /** Days over the allowance, or zero. Never negative. */
    public function daysOver(TaskAssignment $stint): int
    {
        return max(0, $this->daysTaken($stint) - (int) $stint->days_allowed);
    }

    /* ------------------------------- Internals ------------------------------ */

    /** @return array{0: ?string, 1: ?string} the Y-m-d window, or [null, null] */
    protected function window(TaskAssignment $stint): array
    {
        if ($stint->started_on === null) {
            return [null, null];
        }

        $from = $stint->started_on->toDateString();
        $to = $stint->ended_on?->toDateString() ?? Carbon::today()->toDateString();

        return Carbon::parse($to)->lessThan(Carbon::parse($from)) ? [$from, $from] : [$from, $to];
    }

    /**
     * Every date this stint should not be charged for, as Y-m-d.
     *
     * Unioned, so a day that is both blocked and paused costs one day of
     * credit rather than two.
     *
     * @return array<int, string>
     */
    protected function excludedDates(TaskAssignment $stint): array
    {
        [$from, $to] = $this->window($stint);

        return array_values(array_unique(array_merge(
            $this->blockedDates($stint->task, $from, $to),
            $this->pausedDates($stint->task, $from, $to),
            $this->leaveDates($stint, $from, $to),
        )));
    }

    /** @return array<int, string> */
    protected function blockedDates(?Task $task, string $from, string $to): array
    {
        if ($task === null) {
            return [];
        }

        if ($this->blockedByTask !== null) {
            return $this->expand(collect($this->blockedByTask[$task->getKey()] ?? []), $from, $to);
        }

        $periods = TaskStatusPeriod::query()
            ->where('task_id', $task->getKey())
            ->behaving('blocked')
            ->where('started_on', '<=', $to)
            ->where(fn ($query) => $query->whereNull('ended_on')->orWhere('ended_on', '>=', $from))
            ->get(['started_on', 'ended_on']);

        return $this->expand($periods, $from, $to);
    }

    /**
     * Days the person holding this stint was on APPROVED leave.
     *
     * Per person, not per task: the other two exclusions are facts about the
     * work, this one is a fact about who was doing it. Pending leave does not
     * count — it has not been agreed yet — and a declined day certainly does
     * not.
     *
     * @return array<int, string>
     */
    protected function leaveDates(TaskAssignment $stint, string $from, string $to): array
    {
        if ($stint->user_id === null) {
            return [];
        }

        if ($this->leaveByUser !== null) {
            return array_values(array_filter(
                $this->leaveByUser[$stint->user_id] ?? [],
                fn (string $date) => $date >= $from && $date <= $to,
            ));
        }

        return TeamLeaveApplicationDay::query()
            ->where('status', TeamLeaveApplicationDay::APPROVED)
            ->betweenDates($from, $to)
            ->whereHas('application', fn ($query) => $query->where('user_id', $stint->user_id))
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->all();
    }

    /** @return array<int, string> */
    protected function pausedDates(?Task $task, string $from, string $to): array
    {
        if ($task?->project_id === null) {
            return [];
        }

        if ($this->pausedByProject !== null) {
            return $this->expand(collect($this->pausedByProject[$task->project_id] ?? []), $from, $to);
        }

        $periods = ProjectStatusPeriod::query()
            ->where('project_id', $task->project_id)
            ->behaving('paused')
            ->where('started_on', '<=', $to)
            ->where(fn ($query) => $query->whereNull('ended_on')->orWhere('ended_on', '>=', $from))
            ->get(['started_on', 'ended_on']);

        return $this->expand($periods, $from, $to);
    }

    /**
     * Turn periods into the dates they cover, clipped to the window.
     *
     * @param  Collection<int, Model>  $periods
     * @return array<int, string>
     */
    protected function expand($periods, string $from, string $to): array
    {
        $dates = [];
        $windowStart = Carbon::parse($from)->startOfDay();
        $windowEnd = Carbon::parse($to)->startOfDay();

        foreach ($periods as $period) {
            $periodStart = is_array($period) ? $period['started_on'] : $period->started_on;
            $periodEnd = is_array($period) ? $period['ended_on'] : $period->ended_on;

            $start = Carbon::parse($periodStart)->startOfDay()->max($windowStart);
            // An open period runs to the end of the window: a task parked in
            // Blocked and left there is blocked today, not until it is moved.
            $end = $periodEnd === null
                ? $windowEnd
                : Carbon::parse($periodEnd)->startOfDay()->min($windowEnd);

            for ($day = $start->copy(); $day->lessThanOrEqualTo($end); $day->addDay()) {
                $dates[] = $day->toDateString();
            }
        }

        return array_values(array_unique($dates));
    }
}
