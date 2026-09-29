<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ClientQuestion;
use App\Models\DeliveryScoreRecord;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Team;
use App\Models\TeamAbsenceFine;
use App\Models\TeamAttendance;
use App\Models\TeamLeaveApplication;
use App\Models\User;
use App\Services\DeliveryScoreCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every figure on the team dashboard — ONE screen, three audiences.
 *
 * ## Not three screens
 *
 * A member, a lead and an admin see different numbers, and the difference is
 * not three controllers with three queries each: it is the SAME queries run
 * through the SAME scopes, which already answer "whose work is this" for each
 * of them. Task::scopeVisibleTo returns a member their own stints, a lead their
 * teams' work and an admin everything — so "tasks by status" is one query whose
 * answer differs by who asks, and nothing here re-derives that.
 *
 * NO FIGURE ON THIS SCREEN WRITES ITS OWN FILTER. That rule has held for six
 * phases and this is the screen most tempted to break it, because a dashboard
 * is where somebody reaches for a quick count.
 *
 * ## The three tiers, by permission and never by role name
 *
 *   clients.view  (super-admin, admin)  everything, plus clients and money
 *   teams.view    (team-lead)           their teams
 *   otherwise     (team)                themselves
 *
 * The same two strings Project and Task already branch on, so a custom role
 * built on the Roles screen lands in the right tier without being named here.
 *
 * ## Cache for lists, compute live for one person
 *
 * Phase 3's arrangement. A lead's list of member scores reads twelve cached
 * rows; a member's own score is computed live, because it is one person and it
 * has to be right the moment their stint closed. TeamDashboardTest asserts the
 * query count for an admin with several teams, because a dashboard that runs a
 * query per team is the mistake StintClock made twice.
 */
final class TeamDashboard
{
    public const TIER_EVERYTHING = 'everything';

    public const TIER_TEAMS = 'teams';

    public const TIER_OWN = 'own';

    /** Which tier this viewer is in. Permissions, never role names. */
    public static function tierFor(?User $user): string
    {
        return match (true) {
            $user?->can(Project::FULL_VIEW_PERMISSION) => self::TIER_EVERYTHING,
            $user?->can(Task::TEAM_VIEW_PERMISSION) => self::TIER_TEAMS,
            default => self::TIER_OWN,
        };
    }

    /**
     * Everything the screen draws, in one call.
     *
     * @return array<string, mixed>
     */
    public static function for(User $viewer, ?Carbon $month = null): array
    {
        $month = ($month ?? Carbon::today())->copy()->startOfMonth();
        $tier = self::tierFor($viewer);

        return [
            'tier' => $tier,
            'month' => $month,

            // Everyone, scoped. A member's "tasks" are their stints; a lead's
            // are their teams'; an admin's are all of them.
            'tasks_by_status' => self::tasksByStatus($viewer),
            'tasks_overdue' => self::tasksOverdue($viewer),

            // Everyone, about THEMSELVES, on every tier. A lead's own fine is
            // their own money and belongs on their own dashboard; a colleague's
            // never appears, on any tier below the admin one.
            'my_score' => app(DeliveryScoreCalculator::class)->for($viewer),
            'my_attendance' => self::attendanceFor($viewer, $month),
            'my_leave' => TeamLeaveAllowance::balance($viewer->getKey(), $month),
            'my_fines_owed' => self::owedBy($viewer),

            // Their teams, or all of them.
            'projects_running' => $tier === self::TIER_OWN ? null : self::projectsRunning($viewer),
            'projects_overdue' => $tier === self::TIER_OWN ? null : self::projectsOverdue($viewer),
            'member_scores' => $tier === self::TIER_OWN ? null : self::memberScores($viewer),
            'attendance_today' => $tier === self::TIER_OWN ? null : self::attendanceTodayFor($viewer),
            'leave_pending' => $tier === self::TIER_OWN ? null : self::leavePendingFor($viewer),

            // The admin tier only. NOT null-because-zero: null means "not a
            // figure you are shown", which the view renders as nothing at all
            // rather than as a zero somebody could read as news.
            'clients_active' => $tier === self::TIER_EVERYTHING ? Client::query()->active()->count() : null,
            'client_questions_open' => $tier === self::TIER_EVERYTHING ? ClientQuestion::query()->open()->count() : null,
            'contract_value' => $tier === self::TIER_EVERYTHING ? self::contractValue() : null,
            'fines_outstanding' => $tier === self::TIER_EVERYTHING ? self::finesOutstanding() : null,
        ];
    }

    /* -------------------------------- Work ---------------------------------- */

    /**
     * label => count, in the admins' own status order, zeroes included.
     *
     * One grouped query over Task::visibleTo, joined to the status list so a
     * status nobody is on still shows its zero — a board with an empty column
     * is information, and a row that silently disappears is not.
     *
     * @return Collection<string, array{label: string, colour: ?string, count: int}>
     */
    public static function tasksByStatus(User $viewer): Collection
    {
        $counts = Task::query()
            ->visibleTo($viewer)
            ->topLevel()
            ->selectRaw('task_status_id, count(*) as total')
            ->groupBy('task_status_id')
            ->pluck('total', 'task_status_id');

        return TaskStatus::query()
            ->active()
            ->ordered()
            ->get(['id', 'label', 'colour'])
            ->mapWithKeys(fn (TaskStatus $status) => [$status->label => [
                'label' => $status->label,
                'colour' => $status->colour,
                'count' => (int) ($counts[$status->id] ?? 0),
            ]]);
    }

    /** Tasks past their due date that are not done — scoped, like everything else. */
    public static function tasksOverdue(User $viewer): int
    {
        return Task::query()
            ->visibleTo($viewer)
            ->whereNotNull('due_date')
            ->beforeDate(Carbon::today())
            ->whereHas('status', fn (Builder $status) => $status->where('behaviour', '!=', 'done'))
            ->count();
    }

    /* ------------------------------ Projects -------------------------------- */

    public static function projectsRunning(User $viewer): int
    {
        return Project::query()->visibleTo($viewer)->countingDays()->count();
    }

    public static function projectsOverdue(User $viewer): int
    {
        return Project::query()
            ->visibleTo($viewer)
            ->countingDays()
            ->whereNotNull('due_date')
            ->beforeDate(Carbon::today())
            ->count();
    }

    /**
     * The money, for the admin tier only.
     *
     * Behind the same permission the column itself is gated on everywhere else,
     * and reached only from the branch above — two layers, because a figure that
     * is merely unrendered is one template edit from being rendered.
     */
    public static function contractValue(): float
    {
        return (float) Project::query()->countingDays()->sum('contract_value');
    }

    /* ------------------------------- People --------------------------------- */

    /**
     * The people whose figures this viewer may see, as a user-id subquery.
     *
     * Members of the teams they are a member of — the same rule
     * Project::scopeVisibleTo uses, membership rather than the team they lead,
     * because a lead may be a member of several. An admin gets everybody who is
     * on any team.
     */
    public static function peopleQuery(User $viewer): Builder
    {
        $members = User::query()->whereIn('id', fn ($sub) => $sub
            ->select('tm.user_id')
            ->from('team_members as tm'));

        if (self::tierFor($viewer) === self::TIER_EVERYTHING) {
            return $members;
        }

        return User::query()->whereIn('id', fn ($sub) => $sub
            ->select('tm.user_id')
            ->from('team_members as tm')
            ->whereIn('tm.team_id', $viewer->teams()->select('teams.id')));
    }

    /**
     * Each person's delivery score, from the CACHE.
     *
     * A list screen, so it reads the cached rows — twelve members cost one
     * query, not twelve calculations. The viewer's own score on this same
     * screen is computed live, because it is one person.
     *
     * @return Collection<int, array{name: string, score: array<string, mixed>}>
     */
    public static function memberScores(User $viewer): Collection
    {
        return DeliveryScoreRecord::query()
            ->whereIn('user_id', self::peopleQuery($viewer)->select('users.id'))
            ->with('user:id,name')
            ->get()
            ->sortByDesc(fn (DeliveryScoreRecord $row) => $row->percent ?? -1)
            ->values()
            ->map(fn (DeliveryScoreRecord $row) => [
                'name' => $row->user?->name ?? 'Unknown',
                'score' => $row->toScoreArray(),
            ]);
    }

    /* ----------------------------- Attendance ------------------------------- */

    /**
     * One person's month: the counts and the weighted percentage.
     *
     * @return array{counts: array<string, int>, percent: ?float}
     */
    public static function attendanceFor(User $user, Carbon $month): array
    {
        $counts = TeamAttendance::query()
            ->where('user_id', $user->getKey())
            ->betweenDates($month, $month->copy()->endOfMonth())
            ->counted()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();

        return [
            'counts' => $counts,
            // Null when nothing is marked — "not marked yet" and 0% are
            // different statements and stay different to the screen.
            'percent' => AttendanceMath::weightedPercent($counts),
        ];
    }

    /**
     * Today's register for the people this viewer may see.
     *
     * @return array{marked: int, people: int, counts: array<string, int>}
     */
    public static function attendanceTodayFor(User $viewer): array
    {
        $people = self::peopleQuery($viewer)->select('users.id');

        $counts = TeamAttendance::query()
            ->whereIn('user_id', $people)
            ->onDate(Carbon::today())
            ->counted()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();

        return [
            'marked' => array_sum($counts),
            'people' => self::peopleQuery($viewer)->count(),
            'counts' => $counts,
        ];
    }

    /* ------------------------- Leave, and what is owed ---------------------- */

    /**
     * Applications waiting on a decision, for the people this viewer may see.
     *
     * A COUNT and nothing else for a lead: reviewing leave is admin work — a
     * lead is scored on their team's delivery, so approving time off costs them
     * something — and knowing how many are waiting is scheduling, not review.
     * The view draws it without a link for anybody who cannot open the queue,
     * because a control that is offered and then refuses is the bug three
     * phases of this build have been fixing.
     */
    public static function leavePendingFor(User $viewer): int
    {
        return TeamLeaveApplication::query()
            ->pending()
            ->whereIn('user_id', self::peopleQuery($viewer)->select('users.id'))
            ->count();
    }

    /**
     * What THIS PERSON owes. Never what anybody else owes.
     *
     * On every tier including a lead's, because it is their own money — and a
     * lead has never been able to see a colleague's ledger and does not start
     * here. The all-teams figure is finesOutstanding() below, which is reached
     * only from the admin branch.
     */
    public static function owedBy(User $user): float
    {
        return (float) TeamAbsenceFine::query()
            ->where('user_id', $user->getKey())
            ->whereNull('settled_on')
            ->sum('total');
    }

    /** Everybody's unsettled fines — the admin tier only. */
    public static function finesOutstanding(): float
    {
        return (float) TeamAbsenceFine::query()->whereNull('settled_on')->sum('total');
    }

    /** How many teams this viewer's figures are drawn from, for the subtitle. */
    public static function teamCountFor(User $viewer): int
    {
        return self::tierFor($viewer) === self::TIER_EVERYTHING
            ? Team::query()->count()
            : $viewer->teams()->count();
    }
}
