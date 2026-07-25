<x-admin.layout title="Roles & Permissions">
    <x-page-header eyebrow="People" title="Roles &amp; Permissions" description="Control what each role can see and do across the admin panel.">
        <x-slot:actions>
            <x-btn :href="route('admin.roles.create')">
                <x-icon name="plus" class="size-4" /> New role
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Role</th>
                <th class="th">Permissions</th>
                <th class="th">Users</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($roles as $role)
                <tr class="row">
                    <td class="td">
                        <div class="flex items-center gap-3">
                            <div class="flex size-9 items-center justify-center rounded-xl {{ $role->name === 'super-admin' ? 'bg-secondary/10 text-secondary' : 'bg-primary/10 text-primary' }}">
                                <x-icon name="shield" class="size-4.5" />
                            </div>
                            <div>
                                <p class="font-medium text-on-surface">{{ $role->name }}</p>
                                @if ($role->name === 'super-admin')
                                    <p class="text-xs text-outline">Full ownership — bypasses all permission gates</p>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="td">
                        <x-badge variant="primary">{{ $role->permissions_count }} permission{{ $role->permissions_count === 1 ? '' : 's' }}</x-badge>
                    </td>
                    <td class="td font-mono text-xs">{{ $role->users_count }}</td>
                    <td class="td">
                        <div class="flex items-center justify-end gap-1">
                            <a href="{{ route('admin.roles.edit', $role) }}" aria-label="Edit role" title="Edit role" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                <x-icon name="pencil" class="size-4" />
                            </a>
                            @if ($role->name !== 'super-admin')
                                <x-confirm-form :action="route('admin.roles.destroy', $role)" method="DELETE"
                                    title="Delete role" :message="'Delete the '.$role->name.' role? Users holding it will lose its permissions.'" confirm-label="Delete role"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                    <x-icon name="trash" class="size-4" />
                                </x-confirm-form>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </x-table>
</x-admin.layout>
