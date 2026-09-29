<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ManagesTeamComments;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TeamProjectComment;
use App\Support\TeamWorkVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The discussion on a project.
 *
 * Every read and write goes through TeamWorkVisibility, which asks
 * Project::scopeVisibleTo — the one row filter. A discussion on a project you
 * cannot see is a 404, matching projects.show: a refusal would confirm the
 * project exists, which is already a fact about somebody else's client work.
 *
 * A client never sees any of this, and there is no flag that could change that.
 */
class TeamProjectCommentController extends Controller
{
    use ManagesTeamComments;

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->assertVisible($request, $project);

        $data = $this->validatedComment($request, 'team_project_comments');

        $project->comments()->create($data + ['user_id' => $request->user()->id]);

        return back()->with('success', 'Posted.');
    }

    public function update(Request $request, Project $project, TeamProjectComment $comment): RedirectResponse
    {
        $this->assertVisible($request, $project);
        $this->owned($project, $comment);
        $this->assertMayEdit($request->user(), $comment);

        $comment->update(['body' => trim((string) $request->input('body'))]);

        return back()->with('success', 'Updated.');
    }

    public function destroy(Request $request, Project $project, TeamProjectComment $comment): RedirectResponse
    {
        $this->assertVisible($request, $project);
        $this->owned($project, $comment);
        $this->assertMayDelete($request->user(), $comment);

        // Soft delete. Any replies under it stay readable: a thread is other
        // people's words, and removing one must not take the rest.
        $comment->delete();

        return back()->with('success', 'Deleted.');
    }

    protected function assertVisible(Request $request, Project $project): void
    {
        abort_unless(TeamWorkVisibility::seesProject($request->user(), $project), 404);
    }

    /** A comment from another project is not found, not forbidden. */
    protected function owned(Project $project, TeamProjectComment $comment): void
    {
        abort_unless($comment->project_id === $project->getKey(), 404);
    }
}
