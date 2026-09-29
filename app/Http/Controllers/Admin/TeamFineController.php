<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TeamAbsenceFine;
use App\Models\User;
use App\Support\TeamFineRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The absence ledger. Read-only for the person it is about.
 *
 * ## Who sees a fine
 *
 *   the member           their own, read-only
 *   admin, super-admin   everyone's, and may mark one settled
 *   team-lead            NONE. Not their team's, not anyone's
 *
 * A lead already cannot see what a project is worth; what a colleague OWES is
 * more personal than that. There is no lead-shaped view of this screen, and
 * asking for one by URL is answered with 404 rather than 403 — a refusal would
 * confirm the ledger exists, which is itself the fact being protected.
 *
 * No invoices. See TeamFineRules for why AbsenceFineCharge was not extended.
 */
class TeamFineController extends Controller
{
    /** Somebody's ledger — your own, or anyone's if you may see everyone's. */
    public function show(Request $request, User $user): View
    {
        $this->assertVisible($request, $user);

        return view('admin.team-fines.show', [
            'member' => $user,
            'fines' => TeamAbsenceFine::query()
                ->where('user_id', $user->id)
                ->with('settler:id,name')
                ->orderByDesc('month')
                ->get(),
            'allowance' => TeamFineRules::allowance(),
            'rate' => TeamFineRules::perAbsence(),
            'maySettle' => $request->user()->can('clients.view'),
        ]);
    }

    /** Your own, without needing to know your own id. */
    public function mine(Request $request): View
    {
        return $this->show($request, $request->user());
    }

    /** Every outstanding row. Admin only. */
    public function index(Request $request): View
    {
        return view('admin.team-fines.index', [
            'fines' => TeamAbsenceFine::query()
                ->with(['user:id,name', 'settler:id,name'])
                ->orderByDesc('month')
                ->orderBy('user_id')
                ->get(),
        ]);
    }

    /** Mark a month dealt with. Admin only; the row itself is never rewritten. */
    public function settle(Request $request, TeamAbsenceFine $fine): RedirectResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $fine->update([
            'settled_on' => TeamAbsenceFine::dayKey(today()),
            'settled_by' => $request->user()->id,
            'notes' => $data['notes'] ?? $fine->notes,
        ]);

        return back()->with('success', "{$fine->user?->name}'s {$fine->month->format('F Y')} fine is marked settled.");
    }

    /**
     * 404 for anybody else's ledger.
     *
     * Not 403: a refusal tells a team-lead that this person has a ledger worth
     * refusing them, which is the fact being protected.
     */
    protected function assertVisible(Request $request, User $user): void
    {
        $viewer = $request->user();

        abort_unless($viewer->can('clients.view') || $viewer->is($user), 404);
    }
}
