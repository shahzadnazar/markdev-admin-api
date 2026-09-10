<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    use \App\Http\Controllers\Admin\Concerns\FiltersByValues;
    use \App\Http\Controllers\Admin\Concerns\FiltersTrashed;

    /** What the status filter may be asked for. */
    public const STATUSES = ['active', 'inactive'];

    public function index(Request $request): View
    {
        // Managers hold users.view but neither users.delete nor users.restore,
        // so the trashed list showed them rows whose every action refuses.
        $mayViewTrash = $this->mayViewTrash($request, 'users.delete', 'users.restore');
        $trashed = $this->showingTrashed($request, 'users.delete', 'users.restore');

        // Students live in their own module, so they are not offered here and
        // cannot be asked for either — the options bound what the filter takes.
        $roles = Role::where('name', '!=', 'student')->orderBy('name')->pluck('name');
        $roleNames = $this->filterValues($request, 'role', $roles->all());
        $statuses = $this->filterValues($request, 'status', self::STATUSES);

        $users = User::query()
            ->with('roles')
            // Students are managed in the dedicated Students module.
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'student'))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.trim($request->string('search')).'%';
                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->when($roleNames, fn ($query) => $query->role($roleNames))
            // Both ticked is every user, which is the same as neither — so it
            // filters only while exactly one is chosen.
            ->when(count($statuses) === 1, fn ($query) => $query->where('is_active', $statuses[0] === 'active'))
            ->when($trashed, fn ($query) => $query->onlyTrashed())
            ->latest()
            ->paginate(12)
            ->appends(\Illuminate\Support\Arr::except($request->query(), ['partial', 'page']));

        // Live search re-renders only the results, so typing never reloads the
        // page and the cursor stays in the search box. The Filter button still
        // submits the form normally and lands here without `partial`.
        if ($request->boolean('partial')) {
            return view('admin.users._results', ['users' => $users]);
        }

        return view('admin.users.index', [
            'users' => $users,
            'mayViewTrash' => $mayViewTrash,
            'trashed' => $trashed,
            'roles' => $roles,
            'selected' => ['role' => $roleNames, 'status' => $statuses],
        ]);
    }

    public function create(): View
    {
        return view('admin.users.form', [
            'user' => null,
            'roles' => $this->assignableRoles(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $user = User::create([
            ...collect($data)->except('roles')->all(),
            'is_active' => $request->boolean('is_active'),
        ]);
        $user->syncRoles($data['roles'] ?? []);

        return redirect()->route('admin.users.index')->with('success', "User \"{$user->name}\" created.");
    }

    public function edit(User $user): View
    {
        $this->guardPrivilegedTarget($user);

        return view('admin.users.form', [
            'user' => $user->load('roles'),
            'roles' => $this->assignableRoles(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->guardPrivilegedTarget($user);

        $data = $this->validated($request, $user);

        $payload = collect($data)->except(['roles', 'password'])->all();
        if (! empty($data['password'])) {
            $payload['password'] = $data['password'];
        }
        $payload['is_active'] = $request->boolean('is_active');

        $user->update($payload);
        $user->syncRoles($data['roles'] ?? []);

        return redirect()->route('admin.users.index')->with('success', "User \"{$user->name}\" updated.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->guardPrivilegedTarget($user);
        abort_if($user->is($request->user()), 403, 'You cannot delete your own account from here.');

        $user->delete();

        return redirect()->route('admin.users.index')->with('success', "User \"{$user->name}\" moved to trash.");
    }

    public function restore(User $user): RedirectResponse
    {
        $this->guardPrivilegedTarget($user);
        $user->restore();

        return redirect()->route('admin.users.index', ['trashed' => 1])->with('success', "User \"{$user->name}\" restored.");
    }

    public function forceDestroy(Request $request, User $user): RedirectResponse
    {
        $this->guardPrivilegedTarget($user);
        abort_if($user->is($request->user()), 403, 'You cannot delete your own account from here.');

        $user->forceDelete();

        return redirect()->route('admin.users.index', ['trashed' => 1])->with('success', 'User permanently deleted.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'phone' => ['nullable', 'string', 'max:30'],
            'biometric_id' => ['nullable', 'string', 'max:64', Rule::unique('users', 'biometric_id')->ignore($user?->id)],
            'headline' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::in($this->assignableRoles()->pluck('name'))],
        ]);
    }

    /**
     * Roles the current user may hand out. Only a super-admin may grant the
     * super-admin or admin roles.
     */
    protected function assignableRoles()
    {
        return Role::orderBy('name')->get()
            // Students are registered via the Students module, never from here.
            ->reject(fn (Role $role) => $role->name === 'student')
            ->reject(fn (Role $role) => in_array($role->name, ['super-admin', 'admin'], true)
                && ! auth()->user()->hasRole('super-admin'))
            ->values();
    }

    /** Only a super-admin may manage users who hold admin-level roles. */
    protected function guardPrivilegedTarget(User $user): void
    {
        if ($user->hasAnyRole(['super-admin', 'admin']) && ! auth()->user()->hasRole('super-admin')) {
            abort(403, 'Only a super admin can manage administrator accounts.');
        }
    }
}
