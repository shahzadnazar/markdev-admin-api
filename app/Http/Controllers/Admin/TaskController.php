<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Team;
use App\Services\DeliveryScoreCalculator;
use App\Services\StintClock;
use App\Services\TaskWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Tasks: the list, the page, the form, and the move.
 *
 * ## Visibility
 *
 * Every query goes through Task::visibleTo, the one row filter. A task outside
 * your scope is a 404, not a 403 — matching projects.show, and for the same
 * reason: a refusal confirms the task exists, which is already a fact about
 * somebody else's work.
 *
 * ## Two permissions, two jobs
 *
 * `tasks.create` DEFINES work — the form, the allowance, who holds it. Held by
 * team-leads and admins.
 * `tasks.update` MOVES work along — the status, and closing your own stint.
 * Held by members too, deliberately: a member must be able to say they are
 * blocked, and must not be able to rewrite the promise they are judged on.
 *
 * ## The client and the money stay invisible
 *
 * A task page names its project by NAME and CODE and loads nothing else from
 * it. There is no eager load of the client, no contract value in a breadcrumb,
 * and the project link is only rendered for someone who could open that
 * project anyway.
 */
class TaskController extends Controller
{
    public function __construct(
        protected TaskWorkflow $workflow,
        protected StintClock $clock,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $tasks = Task::query()
            ->visibleTo($user)
            ->topLevel()
            // `project:id,name,code,team_id` and NOTHING else. The client id is
            // not even fetched, so no template can reach it by accident.
            ->with(['team:id,name', 'status', 'project:id,name,code,team_id', 'openAssignment.user:id,name', 'parts'])
            ->when($request->filled('team'), fn ($query) => $query->where('team_id', (int) $request->input('team')))
            ->when($request->filled('status'), fn ($query) => $query->where('task_status_id', (int) $request->input('status')))
            ->ordered()
            ->get();

        return view('admin.tasks.index', [
            'tasks' => $tasks,
            'teams' => $this->teamsFor($request),
            'statuses' => TaskStatus::ordered()->get(),
            // The viewer's OWN score, computed live. A screen about one person
            // never reads the cache — that is what list screens are for, and
            // the two disagreeing about the same person is the bug the
            // progress-percent work went to some trouble to avoid.
            'myScore' => app(DeliveryScoreCalculator::class)->for($user),
        ]);
    }

    public function show(Request $request, Task $task): View
    {
        $this->assertVisible($request, $task);

        $task->load([
            'team:id,name',
            'status',
            'project:id,name,code,team_id',
            'parent:id,title',
            'parts.status',
            'parts.openAssignment.user:id,name',
            'assignments.user:id,name',
            'assignments.creator:id,name',
            'statusPeriods.status',
            'statusPeriods.changedBy:id,name',
            'files.uploader:id,name',
            'comments.author:id,name',
            'comments.replies.author:id,name',
            'comments.mentions.user:id,name',
            'comments.replies.mentions.user:id,name',
        ]);

        return view('admin.tasks.show', [
            'task' => $task,
            'clock' => $this->clock,
            'statuses' => TaskStatus::active()->ordered()->get(),
            'members' => $task->team?->members()->orderBy('name')->get(['users.id', 'users.name']) ?? collect(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.tasks.form', $this->formData($request, null));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $task = Task::create($data);
        $this->workflow->openStatusPeriod($task, $request->user());

        return redirect()->route('admin.tasks.show', $task)
            ->with('success', "Task \"{$task->title}\" created.");
    }

    public function edit(Request $request, Task $task): View
    {
        $this->assertVisible($request, $task);

        return view('admin.tasks.form', $this->formData($request, $task));
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        $this->assertVisible($request, $task);

        $task->update($this->validated($request, $task));

        return redirect()->route('admin.tasks.show', $task)
            ->with('success', "Task \"{$task->title}\" updated.");
    }

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        $this->assertVisible($request, $task);

        $title = $task->title;
        $task->delete();

        return redirect()->route('admin.tasks.index')
            ->with('success', "Task \"{$title}\" deleted.");
    }

    /**
     * Move a task to another status — the board's drop, and the page's picker.
     *
     * A blocked target needs a reason; TaskWorkflow refuses without one, so
     * the board and the page cannot disagree about whether it is required.
     */
    public function move(Request $request, Task $task): RedirectResponse
    {
        $this->assertVisible($request, $task);

        $data = $request->validate([
            'task_status_id' => ['required', 'integer', Rule::exists('task_statuses', 'id')->where('is_active', true)],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $status = TaskStatus::findOrFail($data['task_status_id']);

        $this->workflow->changeStatus($task, $status, $request->user(), $data['reason'] ?? null);

        return back()->with('success', "\"{$task->title}\" moved to {$status->label}.");
    }

    /* ------------------------------- Helpers ------------------------------- */

    protected function assertVisible(Request $request, Task $task): void
    {
        abort_unless(
            Task::query()->visibleTo($request->user())->whereKey($task->getKey())->exists(),
            404,
        );
    }

    /** Teams whose work this person can see, for the filter and the form. */
    protected function teamsFor(Request $request)
    {
        $user = $request->user();

        return $user->can(Task::FULL_VIEW_PERMISSION)
            ? Team::active()->ordered()->get(['id', 'name'])
            : $user->teams()->orderBy('name')->get(['teams.id', 'teams.name']);
    }

    /** @return array<string, mixed> */
    protected function formData(Request $request, ?Task $task): array
    {
        $teams = $this->teamsFor($request);

        return [
            'task' => $task,
            'teams' => $teams,
            'statuses' => TaskStatus::active()->ordered()->get(),
            // Only projects of teams this person can see, and only the columns
            // a task form needs. No client, no money.
            'projects' => Project::query()
                ->whereIn('team_id', $teams->pluck('id'))
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'team_id']),
            // Tasks that may be split: top-level ones on the same teams.
            'parents' => Task::query()
                ->visibleTo($request->user())
                ->topLevel()
                ->when($task, fn ($query) => $query->whereKeyNot($task->getKey()))
                ->orderBy('title')
                ->get(['id', 'title', 'team_id']),
        ];
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Task $task = null): array
    {
        $request->merge(['title' => trim((string) $request->input('title'))]);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'team_id' => ['required', 'integer', Rule::exists('teams', 'id')->whereNull('deleted_at')],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'parent_id' => ['nullable', 'integer', Rule::exists('tasks', 'id')->whereNull('deleted_at')],
            'task_status_id' => ['required', 'integer', Rule::exists('task_statuses', 'id')->where('is_active', true)],
            'days_allowed' => ['required', 'integer', 'min:0', 'max:3650'],
            'started_on' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:started_on'],
        ], [
            'task_status_id.exists' => 'Pick a status that is currently on offer — a retired status cannot be given to a task.',
        ]);

        return [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'team_id' => (int) $data['team_id'],
            // Null for internal work, which is the only reason this is nullable.
            'project_id' => ($data['project_id'] ?? null) ? (int) $data['project_id'] : null,
            'parent_id' => ($data['parent_id'] ?? null) ? (int) $data['parent_id'] : null,
            'task_status_id' => (int) $data['task_status_id'],
            'days_allowed' => (int) $data['days_allowed'],
            'started_on' => ($data['started_on'] ?? null) ? Task::dayKey($data['started_on']) : null,
            'due_date' => ($data['due_date'] ?? null) ? Task::dayKey($data['due_date']) : null,
            'sort_order' => $task?->sort_order ?? ((int) Task::max('sort_order') + 1),
        ];
    }
}
