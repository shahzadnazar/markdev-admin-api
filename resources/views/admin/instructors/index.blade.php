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

        <form method="GET" action="{{ route('admin.instructors.index') }}" class="flex items-center gap-2">
            @if ($status)
                <input type="hidden" name="status" value="{{ $status }}">
            @endif
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Search instructors…" class="field w-64">
            <x-btn variant="secondary" size="md">Search</x-btn>
        </form>
    </div>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Instructor</th>
                <th class="th">Assigned courses</th>
                <th class="th">Contact info</th>
                <th class="th">Status</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($instructors as $instructor)
                <tr class="row">
                    <td class="td">
                        <div class="flex items-center gap-3">
                            @if ($instructor->avatar_url)
                                <img src="{{ $instructor->avatar_url }}" alt="" class="size-10 shrink-0 rounded-full object-cover">
                            @else
                                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary to-secondary font-display text-sm font-semibold text-white">
                                    {{ strtoupper(mb_substr($instructor->name, 0, 1)) }}
                                </span>
                            @endif
                            <div class="min-w-0">
                                <a href="{{ route('admin.instructors.show', $instructor) }}"
                                    class="block truncate font-medium text-on-surface hover:text-primary">{{ $instructor->name }}</a>
                                <p class="truncate text-xs text-outline">{{ $instructor->headline ?? 'Instructor' }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="td max-w-[16rem]">
                        @if ($instructor->taughtCourses->isEmpty())
                            <x-badge variant="neutral">not assigned</x-badge>
                        @else
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($instructor->taughtCourses->take(2) as $course)
                                    <x-badge :variant="$loop->odd ? 'primary' : 'secondary'" :title="$course->title">
                                        <span class="inline-block max-w-36 truncate align-bottom normal-case">{{ $course->title }}</span>
                                    </x-badge>
                                @endforeach
                                @if ($instructor->taught_courses_count > 2)
                                    <x-badge variant="neutral">+{{ $instructor->taught_courses_count - 2 }}</x-badge>
                                @endif
                            </div>
                        @endif
                    </td>
                    <td class="td">
                        <p class="text-sm text-on-surface">{{ $instructor->email }}</p>
                        <p class="font-mono text-xs text-outline">{{ $instructor->phone ?? '—' }}</p>
                    </td>
                    <td class="td">
                        <x-badge :variant="$instructor->is_active ? 'success' : 'neutral'">
                            {{ $instructor->is_active ? 'active' : 'inactive' }}
                        </x-badge>
                    </td>
                    <td class="td text-right">
                        <div class="inline-flex items-center gap-1">
                            <x-btn variant="ghost" size="sm" :href="route('admin.instructors.show', $instructor)" title="View profile & schedule">
                                <x-icon name="eye" class="size-4" />
                            </x-btn>
                            @can('users.update')
                                <x-btn variant="ghost" size="sm" :href="route('admin.users.edit', $instructor)" title="Edit">
                                    <x-icon name="pencil" class="size-4" />
                                </x-btn>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><x-empty-state icon="academic-cap" title="No instructors found"
                    description="Add your first instructor, or adjust the filters." /></td></tr>
            @endforelse
        </tbody>
        @if ($instructors->hasPages() || $instructors->total() > 0)
            <x-slot:footer>
                {{ $instructors->links() }}
            </x-slot:footer>
        @endif
    </x-table>
</x-admin.layout>
