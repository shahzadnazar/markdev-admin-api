<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ValidatesLoginCredentials;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    use ValidatesLoginCredentials;

    /**
     * The one role a login created from this screen gets.
     *
     * A constant so the test that pins it and the code that does it read the
     * same word. A screen that creates `users` rows is exactly where an
     * unintended role would slip in unnoticed — `client` holds no admin-panel
     * permission at all, and that is the whole point of using it here.
     */
    public const PORTAL_ROLE = 'client';

    /**
     * The sign-in fields on the client form, under their own prefix.
     *
     * Prefixed because `name` and `email` are already taken on this form by the
     * COMPANY's name and the company's contact address, which are different
     * things on a different table.
     */
    public const PORTAL_FIELDS = ['portal_name', 'portal_email', 'portal_password'];

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

    /**
     * One save, one screen — the company and, optionally, the login.
     *
     * A client needs two rows: a `users` row holding the `client` role and a
     * `clients` row pointing at it. Before this, an admin made them on two
     * screens that did not mention each other, and the project form's client
     * dropdown stayed silently empty until both existed. Somebody lost twenty
     * minutes to that and concluded the app was broken, which was a fair reading.
     *
     * IN A TRANSACTION, because the failure it prevents is the exact state that
     * confusion came from: a company saved with no login, looking finished. The
     * client is written first and the login second, so if the login fails there
     * is a half-made client to lose — and the transaction is what loses it.
     * ClientPortalAccessTest makes User::creating throw to prove that.
     */
    public function store(Request $request): RedirectResponse
    {
        ['client' => $attributes, 'login' => $login] = $this->validated($request);

        $client = DB::transaction(function () use ($attributes, $login) {
            $client = Client::create($attributes);

            if ($login !== null) {
                $client->update(['user_id' => $this->createPortalLogin($login)->getKey()]);
            }

            return $client;
        });

        return redirect()->route('admin.clients.index')->with('success', $login === null
            ? "Client \"{$client->name}\" added."
            : "Client \"{$client->name}\" added, and {$login['portal_email']} can now sign in to the client portal.");
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
        // Edit takes no portal fields: see validated(). The login dropdown is
        // how an existing client is attached to an account here.
        $client->update($this->validated($request, $client)['client']);

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
     * For an account that ALREADY EXISTS — somebody who was given the `client`
     * role from Users, or whose old client record was deleted. A brand-new login
     * comes from the Portal access fields instead, which is why filling both is
     * refused rather than silently resolved one way.
     */
    protected function logins()
    {
        return User::query()->role(self::PORTAL_ROLE)->orderBy('name')->get(['id', 'name', 'email']);
    }

    /**
     * Create the portal login for a client being added.
     *
     * THE CLIENT ROLE AND NOTHING ELSE. syncRoles rather than assignRole: sync
     * states the whole set, so this cannot add `client` on top of something a
     * later edit here might pass in. The role itself holds no admin-panel
     * permission — see RolePermissionSeeder — so this screen cannot mint an
     * account that reaches /admin, and a test asserts exactly that rather than
     * trusting the seeder to stay that way.
     *
     * Active, because an inactive account cannot sign in and the only reason to
     * fill these fields in is to let somebody in.
     */
    protected function createPortalLogin(array $credentials): User
    {
        $user = User::create([
            'name' => $credentials['portal_name'],
            'email' => $credentials['portal_email'],
            'password' => $credentials['portal_password'],
            'is_active' => true,
        ]);

        $user->syncRoles([self::PORTAL_ROLE]);

        return $user;
    }

    /** Has the admin started filling in the Portal access section at all? */
    protected function wantsPortalLogin(Request $request): bool
    {
        return collect(self::PORTAL_FIELDS)->contains(fn (string $field) => $request->filled($field));
    }

    /**
     * The company, and the login if one is being created with it.
     *
     * PORTAL FIELDS ON CREATE ONLY. On edit the client already exists, so the
     * empty-dropdown trap this screen was fixing cannot happen, and "portal
     * access" would need a second meaning for a client who already has a login.
     *
     * @return array{client: array<string, mixed>, login: array<string, mixed>|null}
     */
    protected function validated(Request $request, ?Client $client = null): array
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);

        $wantsLogin = $client === null && $this->wantsPortalLogin($request);

        // REFUSED BEFORE ANYTHING ELSE, and named rather than resolved. Both
        // filled means two different accounts were asked for, and quietly
        // preferring one would attach a client to a login the admin did not
        // choose. Checked ahead of validation so the answer is the conflict
        // rather than a pile of field errors about the half they meant to clear.
        if ($wantsLogin && $request->filled('user_id')) {
            throw ValidationException::withMessages([
                'user_id' => 'Pick one: either choose an existing account in Client login, or fill in Portal access to create a new one. Clear whichever you did not mean.',
            ]);
        }

        $data = $request->validate([
            // Name, email and password for the new login, from the same trait
            // UserController validates with — unique email, the same password
            // rules — so the two screens cannot drift apart. Only when the
            // section is in use: untouched, it is simply not a login.
            ...($wantsLogin ? $this->loginCredentialRules(null, 'portal_') : []),
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
            'portal_name.required' => 'Give the person a name, or clear the Portal access fields.',
            'portal_email.required' => 'Give the person an email to sign in with, or clear the Portal access fields.',
            'portal_email.unique' => 'An account with that email already exists. Choose it in Client login above instead of creating a second one.',
            'portal_email.lowercase' => 'The sign-in address must be lowercase.',
            'portal_password.required' => 'Set a password, or clear the Portal access fields.',
        ]);

        return [
            'client' => [
                'name' => $data['name'],
                'company' => $data['company'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? false),
            ],
            'login' => $wantsLogin
                ? collect($data)->only(self::PORTAL_FIELDS)->all()
                : null,
        ];
    }
}
