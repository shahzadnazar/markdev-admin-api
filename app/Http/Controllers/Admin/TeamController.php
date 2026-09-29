<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Teams of MarkDev staff — the groups client work is assigned to.
 *
 * Built on the attendance-slots module: the same index/form pair, the same
 * soft delete plus an is_active toggle, and the same name rule down to the
 * normalised `name_key` the unique index sits on.
 *
 * This is a TEAM PORTAL screen and reaches no academy data. It is in the admin
 * panel because the people it is about are staff who already sign in here, and
 * because the sidebar, the permissions and the form components are here.
 */
class TeamController extends Controller
{
    public function index(): View
    {
        return view('admin.teams.index', [
            'teams' => Team::query()
                ->with('lead:id,name')
                ->withCount('members')
                ->ordered()
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.teams.form', [
            'team' => null,
            'staff' => $this->staff(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $team = DB::transaction(function () use ($data) {
            $team = Team::create($data['team']);
            $team->members()->sync($data['members']);

            return $team;
        });

        return redirect()->route('admin.teams.index')
            ->with('success', "Team \"{$team->name}\" created with ".count($data['members']).' member(s).');
    }

    public function edit(Team $team): View
    {
        return view('admin.teams.form', [
            'team' => $team->load('members:id'),
            'staff' => $this->staff(),
        ]);
    }

    public function update(Request $request, Team $team): RedirectResponse
    {
        $data = $this->validated($request, $team);

        DB::transaction(function () use ($team, $data) {
            $team->update($data['team']);
            // sync, not attach: someone taken off the list has left the team.
            // Their finished work still names the team, because that lives on
            // the work rather than on this pivot.
            $team->members()->sync($data['members']);
        });

        return redirect()->route('admin.teams.index')
            ->with('success', "Team \"{$team->name}\" updated.");
    }

    /**
     * Soft delete.
     *
     * The team's history stays readable and its name becomes free again —
     * `name_key` is cleared on delete, so next quarter's "Web" is allowed.
     */
    public function destroy(Team $team): RedirectResponse
    {
        $name = $team->name;
        $members = $team->members()->count();
        $team->delete();

        return redirect()->route('admin.teams.index')
            ->with('success', "Team \"{$name}\" deleted. Its {$members} membership(s) and its history are kept.");
    }

    /** Stop pointing new work at a team without disturbing what it has done. */
    public function toggle(Team $team): RedirectResponse
    {
        $team->update(['is_active' => ! $team->is_active]);

        return back()->with('success', $team->is_active
            ? "\"{$team->name}\" is taking new work again."
            : "\"{$team->name}\" is no longer taking new work. Its members, projects and history are unchanged.");
    }

    /* ------------------------------- Helpers ------------------------------- */

    /**
     * Who may be put on a team.
     *
     * Staff. Students hold their own role and reach the system through the
     * portal, so they are left out of the picker — presentation only, like the
     * trashed filter: the validation below asks only that the user exists, so a
     * hand-posted id is not answered with a 403 for something nobody asked
     * about.
     *
     * @return Collection<int, User>
     */
    protected function staff()
    {
        return User::query()
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'student'))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_active']);
    }

    /**
     * @return array{team: array<string, mixed>, members: array<int, int>}
     */
    protected function validated(Request $request, ?Team $team = null): array
    {
        // Trimmed before validating, not after: otherwise "Web" and "Web " are
        // two different names to the uniqueness check and only become the same
        // once they are already both in the table.
        $request->merge(['name' => trim((string) $request->input('name'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'members' => ['required', 'array', 'min:1'],
            'members.*' => ['integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            // Required on the form even though the column is nullable: a team
            // without a lead has nobody accountable for its work. The column is
            // nullable so erasing an account cannot delete the team.
            'team_lead_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'members.required' => 'Pick at least one member — a team with nobody on it has no work to do.',
            'members.min' => 'Pick at least one member — a team with nobody on it has no work to do.',
            'team_lead_id.required' => 'Pick the team lead.',
        ]);

        $members = array_values(array_unique(array_map('intval', $data['members'])));
        $lead = (int) $data['team_lead_id'];

        $this->assertNameIsFree($data['name'], $team);
        $this->assertLeadIsAMember($lead, $members);

        return [
            'team' => [
                'name' => $data['name'],
                'team_lead_id' => $lead,
                'is_active' => (bool) ($data['is_active'] ?? false),
            ],
            'members' => $members,
        ];
    }

    /**
     * One team, one name.
     *
     * Case-insensitive and whitespace-trimmed, scoped to teams that are not in
     * the trash — a deleted team's name is free again. Identical in shape to the
     * attendance-slot rule, including the second half of the condition.
     */
    protected function assertNameIsFree(string $name, ?Team $team): void
    {
        // Editing a team without touching its name always passes: being unable
        // to change a team's lead because of its own name helps nobody.
        if ($team && Team::normaliseName($team->name) === Team::normaliseName($name)) {
            return;
        }

        $key = Team::normaliseName($name);

        $taken = Team::query()
            ->when($team, fn ($query) => $query->whereKeyNot($team->getKey()))
            ->where(function ($query) use ($key) {
                // The stored key first: it is what the unique index sits on, so
                // the form and the database agree on what "the same name" means,
                // and it is indexed.
                $query->where('name_key', $key)
                    // Then the name itself, normalised in SQL. A stored key can
                    // be stale -- a row written before the key existed, or
                    // straight through the query builder -- and a check that
                    // trusted it alone would wave the duplicate through. This
                    // half cannot go stale.
                    ->orWhereRaw("lower(replace(name, ' ', '')) = ?", [$key]);
            })
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => "A team named \"{$name}\" already exists.",
            ]);
        }
    }

    /**
     * The lead is one of the members.
     *
     * A lead who is not on the team is a manager of it, which is a different
     * relationship and not one this models. Checked here rather than in a model
     * hook because the member list and the lead are only both known at the
     * point they are posted together — a `saving` hook runs before the pivot is
     * synced and would see the old list.
     *
     * @param  array<int, int>  $members
     */
    protected function assertLeadIsAMember(int $lead, array $members): void
    {
        if (in_array($lead, $members, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'team_lead_id' => 'The team lead has to be a member of the team. Tick them in the member list, or pick a lead from it.',
        ]);
    }
}
