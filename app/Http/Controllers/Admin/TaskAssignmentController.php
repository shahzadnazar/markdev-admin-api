<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Handing a task to somebody, with a stated allowance.
 *
 * Gated on `tasks.create` — the permission that DEFINES work. A member holds
 * `tasks.update` and can move a task along and say they are blocked; they
 * cannot decide how many days they are judged against, which would make the
 * score self-marked.
 *
 * The form asks for the days every time, including on a handover, because the
 * leaver's remaining days are never carried over: somebody has to make the new
 * promise and be named for it.
 */
class TaskAssignmentController extends Controller
{
    public function __construct(protected TaskWorkflow $workflow) {}

    public function create(Request $request, Task $task): View
    {
        $this->assertVisible($request, $task);

        return view('admin.tasks.assign', [
            'task' => $task->load(['team:id,name', 'openAssignment.user:id,name']),
            'members' => $task->team?->members()->orderBy('name')->get(['users.id', 'users.name']) ?? collect(),
        ]);
    }

    public function store(Request $request, Task $task): RedirectResponse
    {
        $this->assertVisible($request, $task);

        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            // At least one. A stint with no days is a promise nobody can keep,
            // which is exactly what carrying over a leaver's remainder would
            // have produced.
            'days_allowed' => ['required', 'integer', 'min:1', 'max:3650'],
        ], [
            'days_allowed.min' => 'Give this stint at least one day. A handover never carries the last person\'s remaining days over — somebody has to make the new promise.',
        ]);

        $member = User::findOrFail($data['user_id']);

        // On the team doing the work. A task handed to somebody outside it
        // would be invisible to them the moment they opened the board.
        if (! $task->team?->hasMember($member->getKey())) {
            throw ValidationException::withMessages([
                'user_id' => 'That person is not on this task\'s team.',
            ]);
        }

        $this->workflow->assign($task, $member, (int) $data['days_allowed'], $request->user());

        return redirect()->route('admin.tasks.show', $task)
            ->with('success', "{$member->name} has {$data['days_allowed']} day(s) on \"{$task->title}\".");
    }

    protected function assertVisible(Request $request, Task $task): void
    {
        abort_unless(
            Task::query()->visibleTo($request->user())->whereKey($task->getKey())->exists(),
            404,
        );
    }
}
