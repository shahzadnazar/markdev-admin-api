<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ManagesTeamComments;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TeamTaskComment;
use App\Support\TeamWorkVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Comments on a task, so the conversation stays attached to the work.
 *
 * Scoped through Task::scopeVisibleTo, which is narrower than a project's: a
 * team member sees the tasks they hold a stint on, so they see those
 * conversations and no others. A task outside that scope is a 404.
 */
class TeamTaskCommentController extends Controller
{
    use ManagesTeamComments;

    public function store(Request $request, Task $task): RedirectResponse
    {
        $this->assertVisible($request, $task);

        $data = $this->validatedComment($request, 'team_task_comments');

        $task->comments()->create($data + ['user_id' => $request->user()->id]);

        return back()->with('success', 'Posted.');
    }

    public function update(Request $request, Task $task, TeamTaskComment $comment): RedirectResponse
    {
        $this->assertVisible($request, $task);
        $this->owned($task, $comment);
        $this->assertMayEdit($request->user(), $comment);

        $comment->update(['body' => trim((string) $request->input('body'))]);

        return back()->with('success', 'Updated.');
    }

    public function destroy(Request $request, Task $task, TeamTaskComment $comment): RedirectResponse
    {
        $this->assertVisible($request, $task);
        $this->owned($task, $comment);
        $this->assertMayDelete($request->user(), $comment);

        $comment->delete();

        return back()->with('success', 'Deleted.');
    }

    protected function assertVisible(Request $request, Task $task): void
    {
        abort_unless(TeamWorkVisibility::seesTask($request->user(), $task), 404);
    }

    protected function owned(Task $task, TeamTaskComment $comment): void
    {
        abort_unless($comment->task_id === $task->getKey(), 404);
    }
}
