<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectMilestone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The checkpoints on a project, edited from the project's own page.
 *
 * Gated on `projects.update`, which super-admin and admin hold and the team
 * roles do not: a team person reads the milestones on the project page and
 * does not move them. Phase 3 gives them tasks, which is the thing they do own.
 *
 * Every route resolves the milestone THROUGH its project, so a milestone id
 * from another project cannot be edited by guessing the number.
 */
class ProjectMilestoneController extends Controller
{
    public function create(Project $project): View
    {
        return view('admin.projects.milestone-form', [
            'project' => $project,
            'milestone' => null,
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $project->milestones()->create($this->validated($request, $project));

        return redirect()->route('admin.projects.show', $project)
            ->with('success', 'Milestone added.');
    }

    public function edit(Project $project, ProjectMilestone $milestone): View
    {
        return view('admin.projects.milestone-form', [
            'project' => $project,
            'milestone' => $this->owned($project, $milestone),
        ]);
    }

    public function update(Request $request, Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        $this->owned($project, $milestone)->update($this->validated($request, $project, $milestone));

        return redirect()->route('admin.projects.show', $project)
            ->with('success', 'Milestone updated.');
    }

    public function destroy(Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        $this->owned($project, $milestone)->delete();

        return redirect()->route('admin.projects.show', $project)
            ->with('success', 'Milestone deleted.');
    }

    /** Reorder by swapping with the neighbour, as slots and statuses do. */
    public function move(Request $request, Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        $milestone = $this->owned($project, $milestone);
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);

        $neighbour = $project->milestones()
            ->when($data['direction'] === 'up',
                fn ($query) => $query->where('sort_order', '<', $milestone->sort_order)->reorder()->orderByDesc('sort_order'),
                fn ($query) => $query->where('sort_order', '>', $milestone->sort_order)->reorder()->orderBy('sort_order'))
            ->first();

        if ($neighbour) {
            [$milestone->sort_order, $neighbour->sort_order] = [$neighbour->sort_order, $milestone->sort_order];
            $milestone->save();
            $neighbour->save();
        }

        return back();
    }

    /* ------------------------------- Helpers ------------------------------- */

    /** A milestone belonging to another project is not found, not forbidden. */
    protected function owned(Project $project, ProjectMilestone $milestone): ProjectMilestone
    {
        abort_unless($milestone->project_id === $project->getKey(), 404);

        return $milestone;
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, Project $project, ?ProjectMilestone $milestone = null): array
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'due_date' => ['nullable', 'date'],
            'completed_on' => ['nullable', 'date'],
            'is_client_visible' => ['nullable', 'boolean'],
        ]);

        return [
            'name' => $data['name'],
            // Written as "Y-m-d" strings. A Carbon handed to a date column
            // serialises with a time and stops matching; see ScopesToDay.
            'due_date' => ($data['due_date'] ?? null) ? ProjectMilestone::dayKey($data['due_date']) : null,
            // A completion is a DAY, not a flag: "when" answers "whether" and
            // says when, which a boolean would throw away.
            'completed_on' => ($data['completed_on'] ?? null) ? ProjectMilestone::dayKey($data['completed_on']) : null,
            // Written now, rendered by nobody until the client portal in phase 6.
            'is_client_visible' => (bool) ($data['is_client_visible'] ?? false),
            'sort_order' => $milestone?->sort_order ?? ((int) $project->milestones()->max('sort_order') + 1),
        ];
    }
}
