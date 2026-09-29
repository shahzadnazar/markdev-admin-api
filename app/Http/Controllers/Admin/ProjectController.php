<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RefusesDuplicateKeys;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Client work, and who is allowed to look at it.
 *
 * ## The two rules this controller exists to hold
 *
 * WHICH ROWS. Every query runs through Project::visibleTo, which is the single
 * place row visibility is decided: everything for super-admin and admin, and
 * for everyone else only the projects of teams they are a MEMBER of. Nothing
 * here writes its own `where team_id` — a second copy is how a screen added
 * later starts serving somebody else's work.
 *
 * WHICH FIELDS. The client and the money are gated on
 * Project::FULL_VIEW_PERMISSION in the views, and are not even LOADED here for
 * a viewer who may not see them. Two layers on purpose: the gate is what
 * decides, and not fetching the row means an accidental reference in a template
 * has nothing to render.
 *
 * Create, edit and delete are admin-only through their route middleware —
 * `projects.create/update/delete` are held by super-admin and admin alone — so
 * the form screens are never seen by a team person at all. The audit is still
 * worth stating: index and show are the shared ones, and they are where the
 * gates are.
 *
 * There is no export and no JSON endpoint for projects in this phase. When one
 * is added it has to go through visibleTo and the same field gate.
 */
class ProjectController extends Controller
{
    use RefusesDuplicateKeys;

    public function index(Request $request): View
    {
        $user = $request->user();
        $seesEverything = $user->can(Project::FULL_VIEW_PERMISSION);

        $projects = Project::query()
            ->visibleTo($user)
            ->with(['team:id,name', 'status'])
            // Not loaded at all for a team person. The view gates on the same
            // permission; this makes the data absent as well as unrendered.
            ->when($seesEverything, fn ($query) => $query->with('client:id,name,company'))
            ->when($request->filled('team'), fn ($query) => $query->where('team_id', (int) $request->input('team')))
            ->when($request->filled('status'), fn ($query) => $query->where('project_status_id', (int) $request->input('status')))
            // Filtering by client is an admin affordance; for anyone else the
            // parameter is ignored rather than refused, the way the trashed
            // filter is — a copied link is not an attack.
            ->when($seesEverything && $request->filled('client'), fn ($query) => $query->where('client_id', (int) $request->input('client')))
            ->ordered()
            ->get();

        return view('admin.projects.index', [
            'projects' => $projects,
            'teams' => $this->teamsFor($request),
            'statuses' => ProjectStatus::ordered()->get(),
            'clients' => $seesEverything ? Client::ordered()->get(['id', 'name', 'company']) : collect(),
        ]);
    }

    public function show(Request $request, Project $project): View
    {
        $this->assertVisible($request, $project);

        $project->load(['team:id,name', 'status', 'milestones']);

        if ($request->user()->can(Project::FULL_VIEW_PERMISSION)) {
            $project->load('client');
        }

        return view('admin.projects.show', ['project' => $project]);
    }

    public function create(Request $request): View
    {
        return view('admin.projects.form', $this->formData($request, null));
    }

    public function store(Request $request): RedirectResponse
    {
        $project = Project::create($this->validated($request));

        return redirect()->route('admin.projects.show', $project)
            ->with('success', "Project \"{$project->code}\" created.");
    }

    public function edit(Request $request, Project $project): View
    {
        return view('admin.projects.form', $this->formData($request, $project));
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $project->update($this->validated($request, $project));

        return redirect()->route('admin.projects.show', $project)
            ->with('success', "Project \"{$project->code}\" updated.");
    }

    /** Soft delete. The code becomes free again; the history stays readable. */
    public function destroy(Project $project): RedirectResponse
    {
        $code = $project->code;
        $project->delete();

        return redirect()->route('admin.projects.index')
            ->with('success', "Project \"{$code}\" deleted. Its code is available again.");
    }

    /* ------------------------------- Helpers ------------------------------- */

    /**
     * 404 rather than 403 for a project outside this viewer's teams.
     *
     * A 403 confirms the project exists, which for a team person is already a
     * fact about somebody else's client work. Not found is both true from where
     * they are standing and silent.
     */
    protected function assertVisible(Request $request, Project $project): void
    {
        abort_unless(
            Project::query()->visibleTo($request->user())->whereKey($project->getKey())->exists(),
            404,
        );
    }

    /**
     * Teams offered in the filter.
     *
     * An admin gets all of them; anyone else gets the ones they are on, because
     * a filter listing teams whose projects they cannot see is a list of teams
     * they were not supposed to be given either.
     */
    protected function teamsFor(Request $request)
    {
        $user = $request->user();

        return $user->can(Project::FULL_VIEW_PERMISSION)
            ? Team::ordered()->get(['id', 'name'])
            : $user->teams()->orderBy('name')->get(['teams.id', 'teams.name']);
    }

    /** @return array<string, mixed> */
    protected function formData(Request $request, ?Project $project): array
    {
        return [
            'project' => $project,
            'clients' => Client::active()->ordered()->get(['id', 'name', 'company']),
            'teams' => Team::active()->ordered()->get(['id', 'name']),
            // Only statuses on offer. A retired one stays on the projects that
            // already hold it and is never offered to a new one.
            'statuses' => ProjectStatus::active()->ordered()->get(),
        ];
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Project $project = null): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'code' => trim((string) $request->input('code')),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:40'],
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'team_id' => ['required', 'integer', Rule::exists('teams', 'id')->whereNull('deleted_at')],
            // ACTIVE only, enforced in the rule rather than in the dropdown:
            // the form offers active statuses, and a posted id for a retired
            // one has to be refused rather than trusted.
            'project_status_id' => ['required', 'integer', Rule::exists('project_statuses', 'id')->where('is_active', true)],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'contract_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'description' => ['nullable', 'string', 'max:5000'],
        ], [
            'project_status_id.exists' => 'Pick a status that is currently on offer — a retired status cannot be given to a project.',
            'due_date.after_or_equal' => 'The due date cannot be before the start date.',
        ]);

        $this->assertKeyIsFree(
            Project::class,
            $data['code'],
            $project,
            'code',
            "A project with the code \"{$data['code']}\" already exists.",
        );

        return [
            'name' => $data['name'],
            'code' => $data['code'],
            'client_id' => (int) $data['client_id'],
            'team_id' => (int) $data['team_id'],
            'project_status_id' => (int) $data['project_status_id'],
            // Stored as "Y-m-d" strings, never as a Carbon: see ScopesToDay.
            // A `nullable` rule leaves the key out entirely when the field
            // was not posted at all, which is not the same as posting a blank.
            'start_date' => ($data['start_date'] ?? null) ? Project::dayKey($data['start_date']) : null,
            'due_date' => ($data['due_date'] ?? null) ? Project::dayKey($data['due_date']) : null,
            'contract_value' => $data['contract_value'] ?? null,
            'currency' => strtoupper($data['currency']),
            'description' => $data['description'] ?? null,
        ];
    }
}
