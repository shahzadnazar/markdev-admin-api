<x-admin.layout title="Instructors">
    <x-page-header eyebrow="People" title="Instructor management"
        description="View, edit, and manage your academic faculty and their assignments.">
        <x-slot:actions>
            @can('users.create')
                <x-btn :href="route('admin.users.create', ['role' => 'instructor'])">
                    <x-icon name="user-plus" class="size-4" /> Add new instructor
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Faculty stats --}}
    <div class="mb-6 grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-widget label="Total faculty" :value="number_format($totals['faculty'])" icon="academic-cap" tone="primary" />
        <x-stat-widget label="Active" :value="number_format($totals['active'])"
            :sub="$totals['faculty'] - $totals['active'].' inactive'" icon="check" tone="success" />
        <x-stat-widget label="Assigned courses" :value="number_format($totals['courses'])" icon="tag" tone="secondary" />
        <x-stat-widget label="Students taught" :value="number_format($totals['students'])" icon="users" tone="primary" />
    </div>

    {{-- Filters --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="inline-flex rounded-lg bg-white p-1 shadow-card">
            @foreach (['' => 'All instructors', 'active' => 'Active', 'inactive' => 'Inactive'] as $key => $label)
                <a href="{{ route('admin.instructors.index', array_filter(['status' => $key, 'search' => request('search')])) }}"
                    class="rounded-md px-4 py-2 text-sm font-medium transition {{ ($status ?? '') === $key ? 'bg-primary text-white shadow-card' : 'text-on-surface-variant hover:text-on-surface' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('admin.instructors.index') }}" class="flex items-center gap-2" data-live-search="#instructors-results">
            @if ($status)
                <input type="hidden" name="status" value="{{ $status }}">
            @endif
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Search instructors…" class="field w-64">
            <x-btn variant="secondary" size="md">Search</x-btn>
        </form>
    </div>

    <div id="instructors-results" class="transition-opacity duration-150">
        @include('admin.instructors._results')
    </div>
</x-admin.layout>
