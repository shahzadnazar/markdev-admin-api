<?php

namespace App\Exports;

use App\Models\TeamAbsenceFine;
use App\Models\User;
use App\Support\TeamDashboard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The absence fine ledger for one month — ADMIN ONLY.
 *
 * The one export a team-lead may not produce, and the reason is the rule phase
 * 4 established and every phase since has kept: a lead has never been able to
 * see what a colleague owes. Reviewing leave and reading the ledger are admin
 * work because a lead is SCORED on their team's delivery, so both decisions
 * cost them something.
 *
 * The gate is on the report map in TeamReportController, keyed per report the
 * way the academy's transactions export is — not at the route, because the
 * route carries the other three too and a per-route gate would be the loosest
 * of them.
 *
 * Scoped anyway. An admin is the only viewer who reaches this today, and the
 * scope is still applied: "in practice only an admin gets here" is not where
 * this rule is allowed to live.
 */
class TeamFineLedgerExport implements FromCollection, WithHeadings
{
    public function __construct(
        protected User $viewer,
        protected Carbon $month,
    ) {}

    public function headings(): array
    {
        return ['Member', 'Month', 'Allowance', 'Absences', 'Chargeable', 'Rate', 'Total', 'Settled on'];
    }

    public function collection(): Collection
    {
        return $this->rows();
    }

    /** @return Collection<int, array<int, mixed>> */
    public function rows(): Collection
    {
        return TeamAbsenceFine::query()
            ->whereIn('user_id', TeamDashboard::peopleQuery($this->viewer)->select('users.id'))
            ->onDate($this->month->copy()->startOfMonth(), 'month')
            ->with('user:id,name')
            ->get()
            ->sortBy(fn (TeamAbsenceFine $fine) => $fine->user?->name)
            ->values()
            ->map(fn (TeamAbsenceFine $fine) => [
                $fine->user?->name,
                $fine->month->format('F Y'),
                $fine->allowance,
                $fine->absences,
                $fine->chargeable,
                (float) $fine->rate,
                (float) $fine->total,
                $fine->settled_on?->toDateString(),
            ]);
    }
}
