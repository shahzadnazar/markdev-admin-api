<?php

namespace App\Exports;

use App\Models\TeamAttendance;
use App\Models\User;
use App\Support\TeamDashboard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The team register for one month, a row per marked day.
 *
 * Scoped through TeamDashboard::peopleQuery — the same team-membership subquery
 * the dashboard uses, so a lead exports their teams and an admin exports
 * everybody, and neither answer is written twice.
 *
 * NO MONEY. The register says who was in; what an absence cost is the fine
 * ledger, which is a different export behind a different permission.
 */
class TeamAttendanceExport implements FromCollection, WithHeadings
{
    public function __construct(
        protected User $viewer,
        protected Carbon $month,
    ) {}

    public function headings(): array
    {
        return ['Member', 'Date', 'Status', 'Arrived', 'Remarks', 'Marked by'];
    }

    public function collection(): Collection
    {
        return $this->rows();
    }

    /** @return Collection<int, array<int, mixed>> */
    public function rows(): Collection
    {
        return TeamAttendance::query()
            ->whereIn('user_id', TeamDashboard::peopleQuery($this->viewer)->select('users.id'))
            ->betweenDates($this->month->copy()->startOfMonth(), $this->month->copy()->endOfMonth())
            ->decided()
            ->with(['user:id,name', 'marker:id,name'])
            ->orderBy('date')
            ->get()
            ->map(fn (TeamAttendance $row) => [
                $row->user?->name,
                $row->date->toDateString(),
                $row->status,
                $row->arrived_at,
                $row->remarks,
                $row->marker?->name,
            ]);
    }
}
