<?php

namespace App\Exports;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\StintClock;
use App\Support\TeamDashboard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Each member's delivery over a month.
 *
 * ## AN EXPORT BYPASSES EVERY VIEW GATE
 *
 * A report is generated from a QUERY, not from a screen, so not one of the
 * `@can` checks guarding the dashboard applies to it. The scope therefore has
 * to be in the query itself, and it is the same scope the screens use:
 * Task::scopeVisibleTo decides which stints are in range, and
 * TeamDashboard::peopleQuery decides whose names appear. Nothing here writes a
 * `where team_id` of its own.
 *
 * `rows()` is public and returns the finished rows so a test can assert on what
 * a lead's spreadsheet ACTUALLY CONTAINS rather than on the HTTP status of the
 * download — which is the only assertion that would have caught a leak here.
 */
class TeamMemberDeliveryExport implements FromCollection, WithHeadings
{
    public function __construct(
        protected User $viewer,
        protected Carbon $month,
    ) {}

    public function headings(): array
    {
        return [
            'Member', 'Month', 'Stints finished', 'On time or early', 'Late',
            'Days promised', 'Days over', 'Blocked days',
        ];
    }

    public function collection(): Collection
    {
        return $this->rows();
    }

    /** @return Collection<int, array<int, mixed>> */
    public function rows(): Collection
    {
        $from = $this->month->copy()->startOfMonth();
        $to = $this->month->copy()->endOfMonth();

        $stints = TaskAssignment::query()
            // THE SCOPE. Stints on tasks this viewer may see — a lead's teams,
            // an admin's everything, and for anyone else their own stints.
            ->whereIn('task_id', Task::query()->visibleTo($this->viewer)->select('tasks.id'))
            // …and people they may see, which for a lead is their teams'
            // members. Both, because the two narrow different things: a stint
            // is on a task AND held by a person.
            ->whereIn('user_id', TeamDashboard::peopleQuery($this->viewer)->select('users.id'))
            ->whereIn('outcome', TaskAssignment::FINISHED)
            ->betweenDates($from, $to, 'ended_on')
            ->with(['user:id,name', 'task.project', 'task.status'])
            ->get();

        // Three queries for the whole set rather than three per stint — the
        // same priming the live calculator uses.
        app(StintClock::class)->primeFor($stints);

        $clock = app(StintClock::class);

        return $stints
            ->groupBy('user_id')
            ->map(function (Collection $held) use ($clock) {
                $late = $held->where('outcome', 'late');

                return [
                    $held->first()->user?->name ?? 'Unknown',
                    $this->month->format('F Y'),
                    $held->count(),
                    $held->whereIn('outcome', TaskAssignment::KEPT_THE_PROMISE)->count(),
                    $late->count(),
                    (int) $held->sum('days_allowed'),
                    (int) $late->sum(fn (TaskAssignment $stint) => $clock->daysOver($stint)),
                    (int) $held->sum(fn (TaskAssignment $stint) => $clock->blockedDays($stint)),
                ];
            })
            ->values()
            ->sortBy(0)
            ->values();
    }
}
