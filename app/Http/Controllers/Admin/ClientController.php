<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The client book.
 *
 * Every route here carries `clients.*`, which only super-admin and admin hold.
 * That is the whole access story for this module: there is no scoped view of
 * it, because a team person has no business knowing who a project is for.
 */
class ClientController extends Controller
{
    public function index(): View
    {
        return view('admin.clients.index', [
            'clients' => Client::query()
                ->withCount(['projects' => fn ($query) => $query->withTrashed()])
                ->ordered()
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.clients.form', [
            'client' => null,
            'logins' => $this->logins(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $client = Client::create($this->validated($request));

        return redirect()->route('admin.clients.index')
            ->with('success', "Client \"{$client->name}\" added.");
    }

    public function show(Client $client): View
    {
        return view('admin.clients.show', [
            'client' => $client->load('user:id,name,email'),
            // Admin-only screen, so the projects are shown in full.
            'projects' => $client->projects()->with(['team:id,name', 'status'])->ordered()->get(),
        ]);
    }

    public function edit(Client $client): View
    {
        return view('admin.clients.form', [
            'client' => $client,
            'logins' => $this->logins(),
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $client->update($this->validated($request, $client));

        return redirect()->route('admin.clients.index')
            ->with('success', "Client \"{$client->name}\" updated.");
    }

    /**
     * Delete a client — REFUSED, not reassigned, when they have projects.
     *
     * The same shape as refusing to delete a status that is in use, and for the
     * same reason: moving somebody's projects to another client, or nulling the
     * link, is a silent edit to records nobody asked to change. The admin is
     * told the count and deactivates the client instead, which keeps every
     * project readable and stops the client being offered on new ones.
     */
    public function destroy(Client $client): RedirectResponse
    {
        $projects = $client->projectCount();

        if ($projects > 0) {
            throw ValidationException::withMessages([
                'client' => sprintf(
                    '"%s" has %d project(s) and cannot be deleted. Deactivate them instead — their projects stay readable and they stop being offered on new ones.',
                    $client->name,
                    $projects,
                ),
            ]);
        }

        $name = $client->name;
        $client->delete();

        return redirect()->route('admin.clients.index')
            ->with('success', "Client \"{$name}\" deleted.");
    }

    /** Stop offering a client on new projects without disturbing their old ones. */
    public function toggle(Client $client): RedirectResponse
    {
        $client->update(['is_active' => ! $client->is_active]);

        return back()->with('success', $client->is_active
            ? "\"{$client->name}\" is available for new projects again."
            : "\"{$client->name}\" is no longer offered on new projects. Their existing ones are unchanged.");
    }

    /* ------------------------------- Helpers ------------------------------- */

    /**
     * Logins a client record may be attached to.
     *
     * Nothing reads the link in this phase; the client portal is phase 6. The
     * field is on the form now so an admin setting a client up does not have to
     * come back and do it again later.
     */
    protected function logins()
    {
        return User::query()->role('client')->orderBy('name')->get(['id', 'name', 'email']);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Client $client = null): array
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // One login, one client record: two clients sharing a sign-in would
            // each see the other's projects the day phase 6 ships.
            'user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
                Rule::unique('clients', 'user_id')->ignore($client)->whereNull('deleted_at'),
            ],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'user_id.unique' => 'That login is already attached to another client.',
        ]);

        return [
            'name' => $data['name'],
            'company' => $data['company'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'notes' => $data['notes'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }
}
