<x-admin.layout title="Students">
    <x-page-header eyebrow="People" title="Student management"
        description="Every registered student — admissions, documents, enrollments and status.">
        <x-slot:actions>
            @can('students.create')
                <x-btn :href="route('admin.students.create')">
                    <x-icon name="user-plus" class="size-4" /> Register student
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Cohort stats --}}
    <div class="mb-6 grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-widget label="Total students" :value="number_format($totals['students'])" icon="users" tone="primary" />
        <x-stat-widget label="Active" :value="number_format($totals['active'])"
            :sub="$totals['students'] - $totals['active'].' inactive'" icon="check" tone="success" />
        <x-stat-widget label="New this month" :value="number_format($totals['new_month'])" icon="user-plus" tone="secondary" />
        <x-stat-widget label="Course enrollments" :value="number_format($totals['enrollments'])" icon="tag" tone="primary" />
    </div>

    {{-- Filters --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        @if ($trashed)
            <div class="flex items-center gap-2.5 rounded-lg border border-error/20 bg-error-container/40 px-4 py-2.5">
                <x-icon name="trash" class="size-4 shrink-0 text-error" />
                <p class="text-sm text-on-surface-variant">
                    {{ number_format($students->total()) }} removed {{ \Illuminate\Support\Str::plural('student', $students->total()) }}
                    — restore them or remove permanently.
                </p>
            </div>
        @else
            <div class="inline-flex rounded-lg bg-white p-1 shadow-card">
                @php
                    $tabCounts = [null => $totals['students'], 'active' => $totals['active'], 'inactive' => $totals['students'] - $totals['active']];
                @endphp
                @foreach (['' => 'All', 'active' => 'Active', 'inactive' => 'Inactive'] as $key => $label)
                    @php $isActive = ($status ?? '') === $key; @endphp
                    <a href="{{ route('admin.students.index', array_filter(['status' => $key, 'search' => request('search'), 'course' => request('course')])) }}"
                        class="inline-flex items-center gap-1.5 rounded-md px-3.5 py-2 text-sm font-medium transition {{ $isActive ? 'bg-primary text-white shadow-card' : 'text-on-surface-variant hover:text-on-surface' }}">
                        {{ $label }}
                        <span class="rounded-full px-1.5 py-0.5 font-mono text-[10px] leading-none {{ $isActive ? 'bg-white/20 text-white' : 'bg-surface-ice text-on-surface-variant' }}">{{ number_format($tabCounts[$key]) }}</span>
                    </a>
                @endforeach
            </div>
        @endif

        <form method="GET" action="{{ route('admin.students.index') }}" class="flex flex-wrap items-center gap-2">
            @if ($status)
                <input type="hidden" name="status" value="{{ $status }}">
            @endif
            <select name="course" class="field w-56" onchange="this.form.submit()">
                <option value="">All courses</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected(request('course') == $course->id)>{{ $course->title }}</option>
                @endforeach
            </select>
            <input type="search" name="search" value="{{ request('search') }}"
                placeholder="Name, email, reg #, CNIC…" class="field w-64">
            @can('students.delete')
                <label class="flex h-[42px] cursor-pointer items-center gap-2 rounded-lg border border-outline-variant bg-white px-3">
                    <input type="checkbox" name="trashed" value="1" @checked($trashed) onchange="this.form.submit()" class="check">
                    <span class="text-sm text-on-surface-variant">Trash box</span>
                </label>
            @endcan
            <x-btn variant="secondary" size="md">Search</x-btn>
        </form>
    </div>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Student</th>
                <th class="th">CNIC / Contact</th>
                <th class="th">Courses</th>
                <th class="th">Joined</th>
                <th class="th">Status</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($students as $student)
                <tr class="row">
                    <td class="td">
                        <div class="flex items-center gap-3">
                            @if ($student->avatar_url)
                                <img src="{{ $student->avatar_url }}" alt="" class="size-10 shrink-0 rounded-full object-cover">
                            @else
                                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary to-secondary font-display text-sm font-semibold text-white">
                                    {{ strtoupper(mb_substr($student->name, 0, 1)) }}
                                </span>
                            @endif
                            <div class="min-w-0">
                                @if ($student->trashed())
                                    <p class="truncate font-medium text-on-surface">{{ $student->name }}</p>
                                @else
                                    <a href="{{ route('admin.students.show', $student) }}"
                                        class="block truncate font-medium text-on-surface hover:text-primary">{{ $student->name }}</a>
                                @endif
                                <p class="truncate font-mono text-[11px] text-outline">
                                    {{ $student->studentProfile?->reg_no ?? $student->email }}
                                </p>
                            </div>
                        </div>
                    </td>
                    <td class="td">
                        <p class="font-mono text-xs text-on-surface">{{ $student->studentProfile?->cnic ?? '—' }}</p>
                        <p class="font-mono text-xs text-outline">{{ $student->phone ?? $student->email }}</p>
                    </td>
                    <td class="td" style="max-width: 14rem;">
                        @if ($student->enrollments->isEmpty())
                            <span class="font-mono text-xs text-outline">not enrolled</span>
                        @else
                            <p class="truncate text-sm font-medium text-on-surface"
                                title="{{ $student->enrollments->map(fn ($enrollment) => $enrollment->course?->title)->filter()->implode(', ') }}">
                                {{ \Illuminate\Support\Str::limit($student->enrollments->first()->course?->title ?? '—', 26, '…') }}
                            </p>
                            @if ($student->enrollments->count() > 1)
                                <p class="mt-0.5 font-mono text-[11px] text-primary">+{{ $student->enrollments->count() - 1 }} more course{{ $student->enrollments->count() > 2 ? 's' : '' }}</p>
                            @endif
                        @endif
                    </td>
                    <td class="td font-mono text-xs text-on-surface-variant" style="white-space: nowrap;">
                        {{ ($student->studentProfile?->date_of_joining ?? $student->created_at)?->format('M j, Y') }}
                    </td>
                    <td class="td">
                        @if ($student->trashed())
                            <x-badge variant="danger">Removed</x-badge>
                        @else
                            <x-badge :variant="$student->is_active ? 'success' : 'neutral'">
                                {{ $student->is_active ? 'active' : 'inactive' }}
                            </x-badge>
                        @endif
                    </td>
                    <td class="td text-right">
                        <div class="inline-flex items-center gap-1">
                            @if ($student->trashed())
                                @can('students.delete')
                                    <form method="POST" action="{{ route('admin.students.restore', $student) }}" class="inline-flex">
                                        @csrf
                                        <x-btn variant="success" size="sm" title="Restore student">
                                            <x-icon name="restore" class="size-4" /> Restore
                                        </x-btn>
                                    </form>
                                    <x-confirm-form :action="route('admin.students.force-destroy', $student)" method="DELETE" variant="danger"
                                        :title="'Permanently remove ' . $student->name . '?'"
                                        :message="'This cannot be undone — the student\'s account and records are removed for good.'"
                                        confirm-label="Remove permanently"
                                        class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                        <x-icon name="trash" class="size-4" />
                                    </x-confirm-form>
                                @endcan
                            @else
                                <x-btn variant="ghost" size="sm" :href="route('admin.students.show', $student)" title="View profile">
                                    <x-icon name="eye" class="size-4" />
                                </x-btn>
                                @can('students.update')
                                    <x-btn variant="ghost" size="sm" :href="route('admin.students.edit', $student)" title="Edit">
                                        <x-icon name="pencil" class="size-4" />
                                    </x-btn>
                                @endcan
                                @can('students.delete')
                                    <x-confirm-form :action="route('admin.students.destroy', $student)" method="DELETE"
                                        title="Move student to trash?"
                                        :message="'Move '.$student->name.' to trash? They lose portal access; restore from Users → Trashed.'"
                                        confirm-label="Move to trash"
                                        class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                        <x-icon name="trash" class="size-4" />
                                    </x-confirm-form>
                                @endcan
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty-state icon="users" title="No students found"
                    description="Register your first student, or adjust the filters." /></td></tr>
            @endforelse
        </tbody>
        @if ($students->hasPages() || $students->total() > 0)
            <x-slot:footer>
                {{ $students->links() }}
            </x-slot:footer>
        @endif
    </x-table>
</x-admin.layout>
