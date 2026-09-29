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

    /**
     * EARLY AND ON TIME ARE SEPARATE COLUMNS.
     *
     * They were one, "On time or early", and that made the spreadsheet the same
     * shape of useless the screen was before 2427004: the delivery percentage
     * caps at 100, so somebody early on every stint and somebody on time on
     * every stint read the same number — and a scoreboard built from this file
     * could not rank them either, because the one figure that separates them was
     * added into the one beside it.
     *
     * The three finished outcomes now have a column each, and they sum to
     * "Stints finished" — TaskAssignment::FINISHED is exactly early, on_time and
     * late, so a row where they do not add up means an outcome was added to that
     * constant and not to this file. TeamReportTest asserts the sum for that
     * reason.
     */
    public function headings(): array
    {
        return [
            'Member', 'Month', 'Stints finished', 'Early', 'On time', 'Late',
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
                    // Counted separately, not summed into the next one — see
                    // headings(). Both halves of KEPT_THE_PROMISE, apart.
                    $held->where('outcome', 'early')->count(),
                    $held->where('outcome', 'on_time')->count(),
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
