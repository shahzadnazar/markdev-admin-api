<x-admin.layout title="Audit logs">
    <x-page-header eyebrow="System" title="Audit logs" description="Every state change in the platform: who, what, where, and from which device.">
        <x-slot:actions>
            @can('audit-logs.export')
                <x-btn variant="secondary" :href="route('admin.audit-logs.export', request()->query())">
                    <x-icon name="download" class="size-4" /> Export CSV
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar results="audit-logs-results" :action="route('admin.audit-logs.index')">
        <div class="w-full sm:w-56">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="User, action, URL, IP…" class="field">
        </div>
        <x-form.multiselect name="user" label="User" placeholder="Everyone" width="w-48"
            :options="$users->pluck('name', 'id')->all()" :selected="$selected['user']" />
        <x-form.multiselect name="action" label="Action" placeholder="All actions" width="w-44"
            :options="$actions->mapWithKeys(fn ($a) => [$a => $a])->all()" :selected="$selected['action']" />
        <x-form.multiselect name="module" label="Module" placeholder="All modules" width="w-44"
            :options="$modules->mapWithKeys(fn ($m) => [$m => $m])->all()" :selected="$selected['module']" />
        <div class="w-40">
            <x-form.label for="from" value="From" />
            <input type="date" name="from" id="from" value="{{ request('from') }}" class="field">
        </div>
        <div class="w-40">
            <x-form.label for="to" value="To" />
            <input type="date" name="to" id="to" value="{{ request('to') }}" class="field">
        </div>
    </x-filter-bar>

    <div id="audit-logs-results" class="transition-opacity duration-150">
        @include('admin.audit-logs._results')
    </div>

</x-admin.layout>
