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
    public function index(Request $request): View
    {
        $users = User::query()
            ->with('roles')
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.trim($request->string('search')).'%';
                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->when($request->filled('role'), fn ($query) => $query->role($request->string('role')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->string('status')->toString() === 'active'))
            ->when($request->string('trashed')->toString() === '1', fn ($query) => $query->onlyTrashed())
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => Role::orderBy('name')->pluck('name'),
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
