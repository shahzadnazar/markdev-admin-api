<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamAttendance;
use App\Support\AcademyCalendar;
use App\Support\AttendanceMath;
use App\Support\TeamAttendanceConfig;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The team register: marking a team's day, and a member's own month.
 *
 * ## Who marks whom
 *
 *   team-lead            members of teams they are a MEMBER of
 *   admin, super-admin   anyone
 *   team member          nobody
 *
 * The marking screen is gated on `teams.view`, which leads and admins hold and
 * a member does not — the same gate the task board uses to tell a lead from a
 * member, rather than minting a permission the seeder would have to grant.
 * Which TEAM a lead may mark is then a membership question, asked here.
 *
 * ## The absent lock
 *
 * Undoing an absence needs `attendance.correct-absent`, which no team role
 * holds. A lead who marked somebody absent by mistake cannot unmark them —
 * they ask an admin, in writing. That rule is on the MODEL, not here: the
 * academy leaked it twice by leaving it in the controllers that happened to
 * write the register at the time.
 */
class TeamAttendanceController extends Controller
{
    /** The day's register for one team. */
    public function index(Request $request): View
    {
        $teams = $this->markableTeams($request);
        $team = $request->filled('team')
            ? $teams->firstWhere('id', (int) $request->input('team'))
            : $teams->first();

        $day = $this->day($request);

        $members = $team
            ? $team->members()->orderBy('name')->get(['users.id', 'users.name'])
            : collect();

        return view('admin.team-attendance.index', [
            'teams' => $teams,
            'team' => $team,
            'day' => $day,
            'members' => $members,
            'records' => TeamAttendance::query()
                ->whereIn('user_id', $members->pluck('id'))
                ->onDate($day)
                ->get()
                ->keyBy('user_id'),
            'isWorkingDay' => AcademyCalendar::isWorkingWeekday($day) && ! AcademyCalendar::isHoliday($day),
            'holiday' => AcademyCalendar::holidayName($day),
            'lateAfter' => TeamAttendanceConfig::officeStartLabel(),
            'grace' => TeamAttendanceConfig::lateAfterMinutes(),
        ]);
    }

    /** Save the day's marks for one team. */
    public function store(Request $request): RedirectResponse
    {
        $day = $this->day($request);

        $data = $request->validate([
            'team' => ['required', 'integer'],
            'status' => ['required', 'array'],
            'status.*' => [Rule::in(TeamAttendance::STATUSES)],
        ]);

        $team = $this->markableTeams($request)->firstWhere('id', (int) $data['team']);

        // Not a team this person may mark: not found rather than forbidden, the
        // same answer a project or a task outside somebody's scope gives.
        abort_if($team === null, 404);

        $memberIds = $team->members()->pluck('users.id')->all();
        $locked = 0;
        $saved = 0;

        foreach ($data['status'] as $userId => $status) {
            $userId = (int) $userId;

            if (! in_array($userId, $memberIds, true)) {
                continue;
            }

            $record = TeamAttendance::query()
                ->where('user_id', $userId)
                ->onDate($day)
                ->first() ?? new TeamAttendance([
                    'user_id' => $userId,
                    'date' => TeamAttendance::dayKey($day),
                ]);

            // Asked first so the screen can skip the locked rows and say how
            // many it skipped, rather than failing the whole save. The model's
            // own hook is the backstop under this, not a replacement for it.
            if ($record->exists && $record->status === 'absent' && $status !== 'absent'
                && ! TeamAttendance::mayUndoAbsence()) {
                $locked++;

                continue;
            }

            $record->fill([
                'status' => $status,
                'source' => 'manual',
                'marked_by' => $request->user()->id,
                'marked_at' => now(),
            ]);

            try {
                $record->save();
                $saved++;
            } catch (AuthorizationException) {
                $locked++;
            }
        }

        $note = $locked === 0 ? '' : sprintf(
            ' %d absence(s) were left alone — only an admin may undo one.',
            $locked,
        );

        return back()->with('success', "Marked {$saved} member(s) for {$day->format('j M Y')}.".$note);
    }

    /* ------------------------------ My own month ---------------------------- */

    /** A member's own record. Everyone in the portal reaches this, for themselves. */
    public function mine(Request $request): View
    {
        $month = $this->month($request);
        $user = $request->user();

        $records = TeamAttendance::query()
            ->where('user_id', $user->id)
            ->betweenDates($month, $month->copy()->endOfMonth())
            ->orderBy('date')
            ->get();

        $counts = $records->whereIn('status', TeamAttendance::STATUSES)
            ->groupBy('status')
            ->map->count()
            ->all();

        return view('admin.team-attendance.mine', [
            'month' => $month,
            'records' => $records,
            'counts' => $counts,
            // The same weighting the academy register uses, through the same
            // class. The weights are shared; the team's own numbers are the
            // ones that were asked for.
            'percent' => AttendanceMath::weightedPercent($counts),
            'officeStart' => TeamAttendanceConfig::officeStartLabel(),
            'grace' => TeamAttendanceConfig::lateAfterMinutes(),
        ]);
    }

    /* ------------------------------- Helpers ------------------------------- */

    /**
     * Teams whose register this person may mark.
     *
     * An admin marks anyone; a lead marks the teams they are a MEMBER of —
     * membership, not teams.team_lead_id, the same rule projects and tasks use.
     */
    protected function markableTeams(Request $request)
    {
        $user = $request->user();

        return $user->can('clients.view')
            ? Team::active()->ordered()->get(['id', 'name'])
            : $user->teams()->orderBy('name')->get(['teams.id', 'teams.name']);
    }

    protected function day(Request $request): Carbon
    {
        return $request->filled('date')
            ? Carbon::parse($request->input('date'))->startOfDay()
            : Carbon::today();
    }

    protected function month(Request $request): Carbon
    {
        return ($request->filled('month')
            ? Carbon::parse($request->input('month'))
            : Carbon::today())->startOfMonth();
    }
}
