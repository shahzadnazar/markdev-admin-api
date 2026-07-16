<x-admin.layout :title="$role ? 'Edit role' : 'New role'">
    <x-page-header
        eyebrow="People"
        :title="$role ? 'Edit '.$role->name : 'New role'"
        description="Toggle the permissions this role grants, grouped by module."
    >
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.roles.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to roles
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    @php($assigned = old('permissions', $role?->permissions->pluck('name')->all() ?? []))
    @php($seeded = in_array($role?->name, ['super-admin', 'admin', 'manager', 'instructor', 'student'], true))

    <form method="POST" action="{{ $role ? route('admin.roles.update', $role) : route('admin.roles.store') }}" class="max-w-4xl">
        @csrf
        @if ($role) @method('PUT') @endif

        <x-card class="space-y-6">
            <div class="max-w-sm">
                <x-form.input label="Role name" name="name" :value="$role?->name" required
                    :hint="$seeded ? 'Seeded role names are locked to keep the RBAC matrix stable.' : 'Lowercase, hyphenated (e.g. content-editor).'" />
            </div>

            @if ($role?->name === 'super-admin')
                <div class="flex items-start gap-3 rounded-xl bg-secondary/8 p-4">
                    <x-icon name="sparkles" class="size-5 shrink-0 text-secondary" />
                    <p class="text-sm text-on-surface-variant">The super-admin role always holds every permission — the checkboxes below are informational and cannot be reduced.</p>
                </div>
            @endif

            <div>
                <div class="mb-3 flex items-center justify-between">
                    <p class="eyebrow">Permissions by module</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" x-data>
                    @foreach ($groups as $module => $permissions)
                        <div class="rounded-xl border border-outline-variant/60 p-4">
                            <p class="mb-3 font-mono text-[11px] font-medium uppercase tracking-[0.1em] text-on-surface">{{ str_replace('-', ' ', $module) }}</p>
                            <div class="space-y-2">
                                @foreach ($permissions as $permission)
                                    <label class="flex cursor-pointer items-center gap-2.5">
                                        <input type="checkbox" name="permissions[]" value="{{ $permission->name }}" class="check"
                                            @checked(in_array($permission->name, $assigned)) @disabled($role?->name === 'super-admin')>
                                        <span class="text-[13px] text-on-surface-variant">{{ \Illuminate\Support\Str::after($permission->name, '.') }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                <x-form.error name="permissions" />
                <x-form.error name="permissions.*" />
            </div>
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn>
                <x-icon name="check" class="size-4" />
                {{ $role ? 'Save changes' : 'Create role' }}
            </x-btn>
            <x-btn variant="ghost" :href="route('admin.roles.index')">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
