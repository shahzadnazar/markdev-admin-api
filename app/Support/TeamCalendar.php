<?php

namespace App\Support;

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\Task;
use App\Models\TeamLeaveApplicationDay;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A month of the team portal's dates — DERIVED, never stored.
 *
 * ## Why nothing is written
 *
 * There is a `calendar_events` table in this codebase and nothing here touches
 * it, and there is no team events table either. Every date this screen shows
 * already exists somewhere authoritative: a project's start and due dates, a
 * milestone's due date, a task's due date, an approved leave day, a holiday. A
 * stored copy would need a sync job, and the first time somebody moved a due
 * date without running it the calendar would be confidently wrong — which is
 * worse than being empty, because a wrong date is one people plan around.
 * Derived cannot drift.
 *
 * ## The scope wins; the toggle can only narrow
 *
 * Every source starts from the EXISTING row filter — Project::scopeVisibleTo,
 * Task::scopeVisibleTo — and the toggle is applied on top of it. So "My teams"
 * cannot show a member their team's whole board: a member sees the tasks they
 * hold or held a stint on, and a toggle is a preference, not a permission. A
 * task you cannot see appears on no calendar under any toggle, including the
 * admin's "Everything", which is simply the absence of a narrowing on top of a
 * scope that already returns everything for `clients.view`.
 *
 * ## No client, no money
 *
 * A calendar entry says a project's NAME and CODE, which is what phase 2
 * established a team person may know about a project. Nothing here reads
 * `client_id`, `contract_value` or `currency`, and the select lists say so
 * column by column rather than leaving it to a view to remember.
 *
 * ## Colour
 *
 * Reused, not invented. Project, milestone and task entries wear the colour
 * already stored on the project's or task's status, so renaming or recolouring
 * a status in Settings moves the calendar with it. Leave and holidays have no
 * status row to read, so they wear two colours taken from the set those status
 * tables were seeded with — the same palette, not a second one. Colour is never
 * the only signal: every entry carries its own words and the legend names each
 * source, because an admin may recolour any status to anything.
 */
final class TeamCalendar
{
    /** Work assigned to you, plus your own leave. */
    public const MINE = 'mine';

    /** Everything for every team you are a member of. */
    public const TEAMS = 'teams';

    /** Everything. Admin and super-admin only — see scopes(). */
    public const ALL = 'all';

    /**
     * The two sources with no status row to read a colour from.
     *
     * Taken from the palette the status tables shipped with rather than picked
     * fresh: the amber that On Hold was seeded with, and the grey that To Do and
     * Planning were. Not a second palette, and not tokens invented here.
     *
     * They cannot be guaranteed distinct from every status colour, because an
     * admin may recolour any status to anything on the Statuses screens. That is
     * why COLOUR IS NEVER THE ONLY SIGNAL on this calendar: every entry carries
     * its own words, and the legend names each source beside its swatch.
     */
    public const LEAVE_COLOUR = '#9A6400';

    public const HOLIDAY_COLOUR = '#727784';

    /**
     * The toggles this viewer may choose from, in order.
     *
     * "Everything" is offered to whoever holds the permission that already
     * means "sees every project and every task" — the same string Project and
     * Task check — so the toggle list and the row filter cannot disagree about
     * who may ask for everything.
     *
     * @return array<string, string>
     */
    public static function scopes(?User $viewer): array
    {
        $scopes = [
            self::MINE => 'Mine',
            self::TEAMS => 'My teams',
        ];

        if ($viewer?->can(Project::FULL_VIEW_PERMISSION)) {
            $scopes[self::ALL] = 'Everything';
        }

        return $scopes;
    }

    /** A toggle this viewer is actually allowed, falling back to the first. */
    public static function resolveScope(?User $viewer, ?string $requested): string
    {
        $allowed = self::scopes($viewer);

        return isset($allowed[$requested]) ? (string) $requested : (string) array_key_first($allowed);
    }

    /**
     * Every entry in a month, keyed by Y-m-d.
     *
     * One query per source and not one per day: a calendar that asks the
     * database thirty times is the mistake StintClock made, and the query-count
     * assertion in TeamCalendarTest is what would catch it coming back.
     *
     * @return Collection<string, array<int, array<string, mixed>>>
     */
    public static function month(?User $viewer, Carbon $month, string $scope): Collection
    {
        $scope = self::resolveScope($viewer, $scope);
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        $entries = collect()
            ->concat(self::projectEntries($viewer, $from, $to, $scope))
            ->concat(self::milestoneEntries($viewer, $from, $to, $scope))
            ->concat(self::taskEntries($viewer, $from, $to, $scope))
            ->concat(self::leaveEntries($viewer, $from, $to, $scope))
            ->concat(self::holidayEntries($from, $to));

        return $entries
            ->groupBy('date')
            // Inside a day, in source order: the schedule of the work first,
            // then who is away, then whether the academy is even open.
            ->map(fn (Collection $day) => $day->sortBy('sort')->values()->all());
    }

    /* ------------------------------- Sources -------------------------------- */

    /**
     * Project starts and dues — ONE query for both.
     *
     * A project whose start and due both fall inside the month is one row and
     * two entries, which is also why this is not two queries that would each
     * fetch it.
     *
     * Never under "Mine": a project is not assigned to a person, and putting
     * every project of yours under a toggle that means "my work" would make the
     * toggle meaningless.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected static function projectEntries(?User $viewer, Carbon $from, Carbon $to, string $scope): Collection
    {
        if ($scope === self::MINE) {
            return collect();
        }

        $projects = Project::query()
            ->visibleTo($viewer)
            ->when($scope === self::TEAMS, fn (Builder $query) => self::onMyTeams($query, $viewer))
            ->where(fn (Builder $group) => $group
                ->orWhere(fn (Builder $one) => $one->betweenDates($from, $to, 'start_date'))
                ->orWhere(fn (Builder $one) => $one->betweenDates($from, $to, 'due_date')))
            // Named column by column: no client_id, no contract_value, no
            // currency. A select list is a better place for that promise than a
            // view that has to remember it.
            ->get(['id', 'name', 'code', 'team_id', 'project_status_id', 'start_date', 'due_date'])
            ->load('status:id,label,colour');

        return $projects->flatMap(function (Project $project) use ($from, $to) {
            $entries = collect();

            foreach ([['start_date', 'project-start', 'starts', 1], ['due_date', 'project-due', 'due', 2]] as [$column, $source, $verb, $sort]) {
                $date = $project->{$column};

                if ($date === null || $date->lt($from) || $date->gt($to)) {
                    continue;
                }

                $entries->push([
                    'date' => $date->toDateString(),
                    'source' => $source,
                    'sort' => $sort,
                    'label' => sprintf('%s %s', $project->name, $verb),
                    'note' => $project->code,
                    'colour' => $project->status?->colour,
                    'url' => route('admin.projects.show', $project, absolute: false),
                ]);
            }

            return $entries;
        });
    }

    /**
     * Milestone dues, scoped THROUGH the project rather than beside it.
     *
     * A milestone belongs to its project and to nothing else, so "may I see
     * this milestone" is "may I see its project" and is asked that way — a
     * second answer here is a second thing to keep in step.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected static function milestoneEntries(?User $viewer, Carbon $from, Carbon $to, string $scope): Collection
    {
        if ($scope === self::MINE) {
            return collect();
        }

        return ProjectMilestone::query()
            ->betweenDates($from, $to)
            ->whereHas('project', fn (Builder $project) => $project
                ->visibleTo($viewer)
                ->when($scope === self::TEAMS, fn (Builder $query) => self::onMyTeams($query, $viewer)))
            ->with(['project:id,name,code,project_status_id', 'project.status:id,colour'])
            ->get(['id', 'project_id', 'name', 'due_date', 'completed_on'])
            ->map(fn (ProjectMilestone $milestone) => [
                'date' => $milestone->due_date->toDateString(),
                'source' => 'milestone',
                'sort' => 3,
                'label' => $milestone->name,
                'note' => $milestone->project?->code.($milestone->isComplete() ? ' · done' : ''),
                'colour' => $milestone->project?->status?->colour,
                'url' => $milestone->project
                    ? route('admin.projects.show', $milestone->project, absolute: false)
                    : null,
            ]);
    }

    /**
     * Task dues.
     *
     * "Mine" is the stint, not the team: a task is yours while you hold or held
     * a stint on it, which is the same question Task::scopeVisibleTo asks of a
     * plain member. Spelled out here rather than left to the scope, because for
     * an admin the scope returns everything and "Mine" has to mean something
     * for them too.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected static function taskEntries(?User $viewer, Carbon $from, Carbon $to, string $scope): Collection
    {
        return Task::query()
            ->visibleTo($viewer)
            ->betweenDates($from, $to)
            ->when($scope === self::MINE, fn (Builder $query) => $query->whereHas(
                'assignments',
                fn (Builder $stint) => $stint->where('user_id', $viewer?->getKey()),
            ))
            ->when($scope === self::TEAMS, fn (Builder $query) => self::onMyTeams($query, $viewer))
            ->with(['status:id,label,colour', 'project:id,name,code'])
            ->get(['id', 'title', 'team_id', 'project_id', 'task_status_id', 'due_date'])
            ->map(fn (Task $task) => [
                'date' => $task->due_date->toDateString(),
                'source' => 'task',
                'sort' => 4,
                'label' => $task->title,
                'note' => trim(($task->project?->code ?? '').' '.($task->status?->label ?? '')),
                'colour' => $task->status?->colour,
                'url' => route('admin.tasks.show', $task, absolute: false),
            ]);
    }

    /**
     * Approved leave days.
     *
     * APPROVED ONLY. A pending request is not a fact about the month yet, and a
     * declined one never was; showing either would have people planning around
     * a day somebody is going to be at work.
     *
     * Dates and names, and nothing else: no reason, no review note, no fine and
     * no figure. Phase 4 kept a lead away from what a colleague earns or owes
     * and this does not reopen it — what a calendar is FOR is knowing who is
     * around, which is the one fact here.
     *
     * One query, joined rather than eager-loaded: `with('application.user')`
     * would be two more the moment there was any leave to load, which is the
     * same correction the delivery score's leave source needed.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected static function leaveEntries(?User $viewer, Carbon $from, Carbon $to, string $scope): Collection
    {
        if ($viewer === null) {
            return collect();
        }

        return TeamLeaveApplicationDay::query()
            // Qualified, because the join below brings two more tables in and a
            // bare column name in a joined query is a bug waiting for somebody
            // to add a `date` to one of them.
            ->betweenDates($from, $to, 'team_leave_application_days.date')
            ->where('team_leave_application_days.status', TeamLeaveApplicationDay::APPROVED)
            ->join('team_leave_applications', 'team_leave_applications.id', '=', 'team_leave_application_days.team_leave_application_id')
            ->join('users', 'users.id', '=', 'team_leave_applications.user_id')
            ->when($scope === self::MINE, fn ($query) => $query->where('team_leave_applications.user_id', $viewer->getKey()))
            ->when($scope === self::TEAMS, fn ($query) => $query->whereIn(
                'team_leave_applications.user_id',
                fn ($sub) => $sub->select('tm.user_id')
                    ->from('team_members as tm')
                    ->whereIn('tm.team_id', $viewer->teams()->select('teams.id')),
            ))
            ->orderBy('users.name')
            ->get(['team_leave_application_days.date', 'users.name as member_name'])
            ->map(fn (TeamLeaveApplicationDay $day) => [
                'date' => $day->date->toDateString(),
                'source' => 'leave',
                'sort' => 5,
                'label' => $day->member_name.' on leave',
                'note' => null,
                'colour' => self::LEAVE_COLOUR,
                'url' => null,
            ]);
    }

    /**
     * Academy holidays, through AcademyCalendar.
     *
     * The same map every other part of the system reads, so a closure the
     * register honours is a closure the calendar shows. On every toggle: the
     * academy being shut is not a fact about one team.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected static function holidayEntries(Carbon $from, Carbon $to): Collection
    {
        return AcademyCalendar::holidayMap($from, $to)
            ->map(fn (string $name, string $date) => [
                'date' => $date,
                'source' => 'holiday',
                'sort' => 6,
                'label' => $name,
                'note' => 'Academy closed',
                'colour' => self::HOLIDAY_COLOUR,
                'url' => null,
            ])
            ->values();
    }

    /* ------------------------------- Helpers -------------------------------- */

    /**
     * Narrow to the teams this person is a MEMBER of.
     *
     * Membership, not the team they lead — a lead leads one and may be a member
     * of several, the same rule Project::scopeVisibleTo uses. A subquery rather
     * than a fetched list of ids, so the toggle costs no extra round trip.
     *
     * For a lead this repeats a clause the scope has already applied, and that
     * is left alone deliberately: the toggle does not know what the scope did to
     * the query and must not guess. Skipping it "because visibleTo already
     * narrows for this role" would be a second copy of the permission rules
     * living here, which is exactly what a single scope exists to prevent. It
     * costs no extra round trip either way.
     */
    protected static function onMyTeams(Builder $query, ?User $viewer): Builder
    {
        if ($viewer === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('team_id', $viewer->teams()->select('teams.id'));
    }

    /**
     * The legend: one row per source, with the colour it is drawn in.
     *
     * Derived from the month's own entries, so a legend never offers a key for
     * something that is not on the screen — and cannot omit one that is.
     *
     * @param  Collection<string, array<int, array<string, mixed>>>  $days
     * @return array<int, array{source: string, label: string, colour: ?string}>
     */
    public static function legend(Collection $days): array
    {
        return $days
            ->flatten(1)
            ->unique('source')
            ->sortBy('sort')
            ->map(fn (array $entry) => [
                'source' => $entry['source'],
                'label' => self::SOURCE_LABELS[$entry['source']] ?? $entry['source'],
                'colour' => $entry['colour'],
            ])
            ->values()
            ->all();
    }

    /** @var array<string, string> */
    public const SOURCE_LABELS = [
        'project-start' => 'Project starts',
        'project-due' => 'Project due',
        'milestone' => 'Milestone',
        'task' => 'Task due',
        'leave' => 'Leave',
        'holiday' => 'Holiday',
    ];
}
