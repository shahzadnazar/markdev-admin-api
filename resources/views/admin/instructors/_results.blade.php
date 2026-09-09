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
            <div data-pagination>{{ $instructors->links() }}</div>
        </x-slot:footer>
    @endif
</x-table>
