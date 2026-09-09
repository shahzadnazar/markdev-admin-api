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
        <div class="w-48">
            <x-form.label for="user" value="User" />
            <select name="user" id="user" class="field">
                <option value="">Everyone</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}" @selected(request('user') == $user->id)>{{ $user->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="w-44">
            <x-form.label for="action" value="Action" />
            <select name="action" id="action" class="field">
                <option value="">All actions</option>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected(request('action') === $action)>{{ str_replace('_', ' ', $action) }}</option>
                @endforeach
            </select>
        </div>
        <div class="w-44">
            <x-form.label for="module" value="Module" />
            <select name="module" id="module" class="field">
                <option value="">All modules</option>
                @foreach ($modules as $module)
                    <option value="{{ $module }}" @selected(request('module') === $module)>{{ str_replace('_', ' ', $module) }}</option>
                @endforeach
            </select>
        </div>
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
