<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): View
    {
        return view('admin.roles.index', [
            'roles' => Role::withCount(['permissions', 'users'])->orderBy('name')->paginate(25),
        ]);
    }

    public function create(): View
    {
        return view('admin.roles.form', [
            'role' => null,
            'groups' => $this->groupedPermissions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $role = Role::create(['name' => Str::slug($data['name']), 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions'] ?? []);

        return redirect()->route('admin.roles.index')->with('success', "Role \"{$role->name}\" created.");
    }

    public function edit(Role $role): View
    {
        return view('admin.roles.form', [
            'role' => $role->load('permissions'),
            'groups' => $this->groupedPermissions(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        // Keep the seeded role names stable; only custom roles can be renamed.
        if (! in_array($role->name, $this->seededRoles(), true)) {
            $role->update(['name' => Str::slug($data['name'])]);
        }

        // Super-admin keeps every permission regardless of the submitted set.
        if ($role->name !== 'super-admin') {
            $role->syncPermissions($data['permissions'] ?? []);
        }

        return redirect()->route('admin.roles.index')->with('success', "Role \"{$role->name}\" updated.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_if($role->name === 'super-admin', 403, 'The super-admin role cannot be deleted.');

        $name = $role->name;
        $role->delete();

        return redirect()->route('admin.roles.index')->with('success', "Role \"{$name}\" deleted.");
    }

    /** @return array<string, \Illuminate\Support\Collection> */
    protected function groupedPermissions(): array
    {
        return Permission::orderBy('name')->get()
            ->groupBy(fn (Permission $permission) => Str::before($permission->name, '.'))
            ->sortKeys()
            ->all();
    }

    /** @return string[] */
    protected function seededRoles(): array
    {
        return ['super-admin', 'admin', 'manager', 'instructor', 'student'];
    }
}
