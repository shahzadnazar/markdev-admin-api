<x-admin.layout title="Users">
    <x-page-header eyebrow="People" title="Staff &amp; users" description="Administrators, managers and instructors. Students are managed in the Students module.">
        <x-slot:actions>
            @can('users.create')
                <x-btn :href="route('admin.users.create')">
                    <x-icon name="plus" class="size-4" /> New user
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar results="users-results" :action="route('admin.users.index')">
        <div class="w-full sm:w-64">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Name, email or phone…" class="field">
        </div>
        <x-form.multiselect name="role" label="Role" placeholder="All roles" width="w-40"
            :options="$roles->mapWithKeys(fn ($r) => [$r => ucfirst(str_replace('-', ' ', $r))])->all()"
            :selected="$selected['role']" />

        <x-form.multiselect name="status" label="Status" placeholder="Any" width="w-36"
            :options="['active' => 'Active', 'inactive' => 'Inactive']" :selected="$selected['status']" />

        {{-- Only for someone who could restore or empty it. The controller
             decides, so the checkbox and the query string cannot disagree. --}}
        @if ($mayViewTrash)
            <label class="flex h-[42px] cursor-pointer items-center gap-2 rounded-lg border border-outline-variant bg-white px-3">
                <input type="checkbox" name="trashed" value="1" @checked($trashed) class="check">
                <span class="text-sm text-on-surface-variant">Trashed</span>
            </label>
        @endif
    </x-filter-bar>

    <div id="users-results" class="transition-opacity duration-150">
        @include('admin.users._results')
    </div>

</x-admin.layout>
