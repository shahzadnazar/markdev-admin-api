<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The board: one team's tasks, in columns of active statuses.
 *
 * Columns come from the statuses themselves in sort_order, so an admin who
 * adds "Waiting on client" gets a column for it with no code change — and a
 * retired status keeps the tasks already on it without being offered as a
 * destination.
 *
 * Dropping into a BLOCKED column asks for the reason before it commits. The
 * board posts to the same move endpoint the task page uses, so the rule is
 * enforced once, in TaskWorkflow, rather than twice.
 *
 * Scoped by Task::visibleTo like everything else: a member's board shows the
 * work they hold, a lead's shows their teams', an admin's shows all of it.
 */
class TaskBoardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $teams = $user->can(Task::FULL_VIEW_PERMISSION)
            ? Team::active()->ordered()->get(['id', 'name'])
            : $user->teams()->orderBy('name')->get(['teams.id', 'teams.name']);

        $team = $request->filled('team')
            ? $teams->firstWhere('id', (int) $request->input('team'))
            : $teams->first();

        $tasks = Task::query()
            ->visibleTo($user)
            ->topLevel()
            ->when($team, fn ($query) => $query->where('team_id', $team->id))
            // The project by name and code only. No client, no money — a board
            // is a work surface and neither belongs on it.
            ->with(['status', 'project:id,name,code,team_id', 'openAssignment.user:id,name'])
            ->ordered()
            ->get();

        $statuses = TaskStatus::active()->ordered()->get();

        return view('admin.tasks.board', [
            'statuses' => $statuses,
            'columns' => $statuses->mapWithKeys(fn (TaskStatus $status) => [
                $status->id => $tasks->where('task_status_id', $status->id)->values(),
            ]),
            'teams' => $teams,
            'team' => $team,
        ]);
    }
}
