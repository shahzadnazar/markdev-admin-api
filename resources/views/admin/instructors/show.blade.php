<x-admin.layout :title="$instructor->name">
    <x-page-header eyebrow="People" :title="$instructor->name" :description="$instructor->headline"
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Instructors' => route('admin.instructors.index'), $instructor->name => null]">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.instructors.index')">
                <x-icon name="arrow-left" class="size-4" /> All instructors
            </x-btn>
            @can('users.update')
                <x-btn variant="secondary" :href="route('admin.users.edit', $instructor)">
                    <x-icon name="pencil" class="size-4" /> Edit
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-widget label="Courses" :value="number_format($courses->count())" icon="tag" tone="primary" />
        <x-stat-widget label="Students" :value="number_format($students)" icon="users" tone="secondary" />
        <x-stat-widget label="Pending grading" :value="number_format($pendingGrading)" icon="clipboard"
            :tone="$pendingGrading > 0 ? 'warning' : 'success'" sub="submissions waiting" />
        <x-stat-widget label="Status" :value="$instructor->is_active ? 'Active' : 'Inactive'" icon="check"
            :tone="$instructor->is_active ? 'success' : 'danger'"
            :sub="'joined '.$instructor->created_at->format('M Y')" />
    </div>

    <div class="grid gap-6 xl:grid-cols-[1fr_24rem]">
        <div class="space-y-6">
            {{-- Profile --}}
            <x-card>
                <div class="flex items-start gap-5">
                    @if ($instructor->avatar_url)
                        <img src="{{ $instructor->avatar_url }}" alt="" class="size-16 shrink-0 rounded-2xl object-cover">
                    @else
                        <span class="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-primary to-secondary font-display text-xl font-bold text-white">
                            {{ strtoupper(mb_substr($instructor->name, 0, 1)) }}
                        </span>
                    @endif
                    <div class="min-w-0">
                        <h2 class="font-display text-lg font-semibold text-on-surface">{{ $instructor->name }}</h2>
                        <p class="text-sm text-on-surface-variant">{{ $instructor->headline ?? 'Instructor' }}</p>
                        <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 font-mono text-xs text-outline">
                            <span>{{ $instructor->email }}</span>
                            @if ($instructor->phone)
                                <span>{{ $instructor->phone }}</span>
                            @endif
                        </div>
                        @if ($instructor->bio)
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-on-surface-variant">{{ $instructor->bio }}</p>
                        @endif
                    </div>
                </div>
            </x-card>

            {{-- Assigned courses --}}
            <x-table>
                <thead class="bg-surface-ice/60">
                    <tr>
                        <th class="th">Course</th>
                        <th class="th">Level</th>
                        <th class="th">Lessons</th>
                        <th class="th">Students</th>
                        <th class="th">Status</th>
                        <th class="th text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($courses as $course)
                        <tr class="row">
                            <td class="td max-w-[20rem]"><p class="truncate font-medium text-on-surface">{{ $course->title }}</p></td>
                            <td class="td"><x-badge variant="neutral">{{ $course->level }}</x-badge></td>
                            <td class="td font-mono text-xs text-on-surface-variant">{{ $course->lessons_count }}</td>
                            <td class="td font-mono text-xs text-on-surface-variant">{{ $course->enrollments_count }}</td>
                            <td class="td">
                                <x-badge :variant="['published' => 'success', 'draft' => 'neutral', 'archived' => 'neutral'][$course->status] ?? 'neutral'">
                                    {{ $course->status }}
                                </x-badge>
                            </td>
                            <td class="td text-right">
                                <x-btn variant="ghost" size="sm" :href="route('admin.courses.show', $course)" aria-label="Open course" title="Open course">
                                    <x-icon name="eye" class="size-4" />
                                </x-btn>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-empty-state icon="tag" title="No courses assigned"
                            description="Assign this instructor to a course from the course form." /></td></tr>
                    @endforelse
                </tbody>
            </x-table>
        </div>

        {{-- Schedule --}}
        <x-card class="self-start">
            <div class="mb-4 flex items-center gap-2.5">
                <x-icon name="calendar" class="size-4 text-primary" />
                <h2 class="font-mono text-label-md uppercase text-on-surface">Upcoming schedule</h2>
            </div>
            @forelse ($schedule as $event)
                <div class="flex items-start gap-3 border-b border-surface-ice py-3 last:border-0">
                    <div class="flex size-10 shrink-0 flex-col items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <span class="font-mono text-[10px] uppercase leading-3">{{ $event->starts_at->format('M') }}</span>
                        <span class="font-display text-sm font-bold leading-4">{{ $event->starts_at->format('j') }}</span>
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-on-surface">{{ $event->title }}</p>
                        <p class="font-mono text-[11px] text-outline">
                            {{ $event->starts_at->format('D · g:i A') }}{{ $event->course ? ' · '.\Illuminate\Support\Str::limit($event->course->title, 24) : '' }}
                        </p>
                    </div>
                </div>
            @empty
                <p class="py-6 text-center text-sm text-on-surface-variant">No upcoming sessions scheduled.</p>
            @endforelse
        </x-card>
    </div>
</x-admin.layout>
