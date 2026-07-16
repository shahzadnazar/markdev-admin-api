<x-admin.layout title="Users">
    <x-page-header eyebrow="People" title="Users" description="Manage every account on the platform — staff, instructors and students.">
        <x-slot:actions>
            @can('users.create')
                <x-btn :href="route('admin.users.create')">
                    <x-icon name="plus" class="size-4" /> New user
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar :action="route('admin.users.index')">
        <div class="w-full sm:w-64">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Name, email or phone…" class="field">
        </div>
        <div class="w-40">
            <x-form.label for="role" value="Role" />
            <select name="role" id="role" class="field">
                <option value="">All roles</option>
                @foreach ($roles as $role)
                    <option value="{{ $role }}" @selected(request('role') === $role)>{{ $role }}</option>
                @endforeach
            </select>
        </div>
        <div class="w-36">
            <x-form.label for="status" value="Status" />
            <select name="status" id="status" class="field">
                <option value="">Any</option>
                <option value="active" @selected(request('status') === 'active')>Active</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
            </select>
        </div>
        <label class="flex h-[42px] cursor-pointer items-center gap-2 rounded-lg border border-outline-variant bg-white px-3">
            <input type="checkbox" name="trashed" value="1" @checked(request('trashed') === '1') class="check">
            <span class="text-sm text-on-surface-variant">Trashed</span>
        </label>
    </x-filter-bar>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">User</th>
                <th class="th">Roles</th>
                <th class="th">Phone</th>
                <th class="th">Status</th>
                <th class="th">Joined</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $user)
                <tr class="row">
                    <td class="td">
                        <div class="flex items-center gap-3">
                            <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary to-secondary font-display text-sm font-semibold text-white">
                                {{ strtoupper(mb_substr($user->name, 0, 1)) }}
                            </div>
                            <div class="min-w-0">
                                <p class="truncate font-medium text-on-surface">{{ $user->name }}</p>
                                <p class="truncate text-xs text-outline">{{ $user->email }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="td">
                        <div class="flex flex-wrap gap-1">
                            @forelse ($user->roles as $role)
                                <x-badge :variant="in_array($role->name, ['super-admin', 'admin']) ? 'secondary' : 'primary'">{{ $role->name }}</x-badge>
                            @empty
                                <span class="text-xs text-outline">—</span>
                            @endforelse
                        </div>
                    </td>
                    <td class="td font-mono text-xs">{{ $user->phone ?? '—' }}</td>
                    <td class="td">
                        @if ($user->trashed())
                            <x-badge variant="danger">Trashed</x-badge>
                        @elseif ($user->is_active)
                            <x-badge variant="success">Active</x-badge>
                        @else
                            <x-badge variant="warning">Inactive</x-badge>
                        @endif
                    </td>
                    <td class="td font-mono text-xs text-outline">{{ $user->created_at?->format('M j, Y') }}</td>
                    <td class="td">
                        <div class="flex items-center justify-end gap-1">
                            @if ($user->trashed())
                                @can('users.restore')
                                    <x-confirm-form :action="route('admin.users.restore', $user)" method="POST" variant="primary"
                                        title="Restore user" :message="'Restore '.$user->name.' and reactivate their account?'" confirm-label="Restore"
                                        class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                        <x-icon name="restore" class="size-4" />
                                    </x-confirm-form>
                                @endcan
                                @can('users.delete')
                                    <x-confirm-form :action="route('admin.users.force-destroy', $user)" method="DELETE"
                                        title="Delete forever" :message="'Permanently delete '.$user->name.'? This cannot be undone.'" confirm-label="Delete forever"
                                        class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                        <x-icon name="trash" class="size-4" />
                                    </x-confirm-form>
                                @endcan
                            @else
                                @can('users.update')
                                    <a href="{{ route('admin.users.edit', $user) }}" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                        <x-icon name="pencil" class="size-4" />
                                    </a>
                                @endcan
                                @can('users.delete')
                                    <x-confirm-form :action="route('admin.users.destroy', $user)" method="DELETE"
                                        title="Move to trash" :message="'Move '.$user->name.' to trash? You can restore them later.'" confirm-label="Move to trash"
                                        class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                        <x-icon name="trash" class="size-4" />
                                    </x-confirm-form>
                                @endcan
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-empty-state icon="users" title="No users found" description="Try adjusting your search or filters." />
                    </td>
                </tr>
            @endforelse
        </tbody>
        <x-slot:footer>
            {{ $users->links() }}
        </x-slot:footer>
    </x-table>
</x-admin.layout>
