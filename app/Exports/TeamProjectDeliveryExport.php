<?php

namespace App\Exports;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\StintClock;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Days promised against days taken, per project.
 *
 * ## NO MONEY AND NO CLIENT, in any column
 *
 * A project is its name, its code and its status — the three things phase 2
 * established a team person may know about one. `contract_value`, `currency`
 * and `client_id` are not merely left out of the headings: they are not
 * selected, so a column added carelessly later has nothing to put in it.
 *
 * ## The scope is Project::visibleTo
 *
 * An export is a query, not a screen, so the gate has to be in the query. This
 * is the same scope the project list and the calendar use — a lead gets their
 * teams' projects, an admin gets all of them, and neither answer is re-derived
 * here.
 */
class TeamProjectDeliveryExport implements FromCollection, WithHeadings
{
    public function __construct(protected User $viewer) {}

    public function headings(): array
    {
        return ['Project', 'Code', 'Status', 'Tasks', 'Days promised', 'Days taken', 'Variance'];
    }

    public function collection(): Collection
    {
        return $this->rows();
    }

    /** @return Collection<int, array<int, mixed>> */
    public function rows(): Collection
    {
        $projects = Project::query()
            ->visibleTo($this->viewer)
            ->with('status:id,label')
            ->ordered()
            // Column by column: no client_id, no contract_value, no currency.
            // The select list is a better place for that promise than a
            // heading somebody could add a ninth entry to.
            ->get(['id', 'name', 'code', 'project_status_id']);

        if ($projects->isEmpty()) {
            return collect();
        }

        $promised = Task::query()
            ->whereIn('project_id', $projects->modelKeys())
            ->selectRaw('project_id, count(*) as tasks, coalesce(sum(days_allowed), 0) as promised')
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        $stints = TaskAssignment::query()
            ->whereIn('task_id', Task::query()->whereIn('project_id', $projects->modelKeys())->select('tasks.id'))
            ->finished()
            ->with(['task.project', 'task.status'])
            ->get();

        app(StintClock::class)->primeFor($stints);
        $clock = app(StintClock::class);

        $taken = $stints
            ->groupBy(fn (TaskAssignment $stint) => $stint->task?->project_id)
            ->map(fn (Collection $held) => (int) $held->sum(fn (TaskAssignment $stint) => $clock->daysTaken($stint)));

        return $projects->map(function (Project $project) use ($promised, $taken) {
            $days = (int) ($promised[$project->id]->promised ?? 0);
            $spent = (int) ($taken[$project->id] ?? 0);

            return [
                $project->name,
                $project->code,
                $project->status?->label,
                (int) ($promised[$project->id]->tasks ?? 0),
                $days,
                $spent,
                // Signed: negative is under, positive is over. The sign is the
                // whole point, so it is not made absolute for tidiness.
                $spent - $days,
            ];
        })->values();
    }
}
