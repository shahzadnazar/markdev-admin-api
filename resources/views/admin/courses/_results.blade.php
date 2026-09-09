<x-table>
    <thead class="bg-surface-ice/60">
        <tr>
            <th class="th">Course</th>
            <th class="th">Instructor</th>
            <th class="th">Level</th>
            <th class="th td-num">Fee</th>
            <th class="th">Duration</th>
            <th class="th">Enrolled</th>
            <th class="th">Status</th>
            <th class="th text-right">Actions</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($courses as $course)
            <tr class="row">
                <td class="td">
                    <div class="flex items-center gap-3">
                        @if ($course->thumbnail_path)
                            <img src="{{ $course->thumbnail_url }}" alt="" class="size-10 shrink-0 rounded-lg object-cover">
                        @else
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-primary/15 to-secondary/15 text-primary">
                                <x-icon name="academic-cap" class="size-5" />
                            </div>
                        @endif
                        <div class="min-w-0">
                            @if ($course->trashed())
                                <p class="max-w-[16rem] truncate font-medium text-on-surface">{{ $course->title }}</p>
                            @else
                                <a href="{{ route('admin.courses.show', $course) }}" class="block max-w-[16rem] truncate font-medium text-on-surface hover:text-primary">{{ $course->title }}</a>
                            @endif
                            <p class="text-xs text-outline">{{ $course->category?->name ?? 'Uncategorised' }}</p>
                        </div>
                    </div>
                </td>
                <td class="td text-on-surface-variant">{{ $course->instructor?->name ?? '—' }}</td>
                <td class="td"><x-badge variant="secondary">{{ $course->level }}</x-badge></td>
                <td class="td td-num font-mono text-xs" style="white-space: nowrap;">{{ $course->is_free ? 'Free' : 'Rs '.number_format((float) $course->price) }}</td>
                <td class="td font-mono text-xs" style="white-space: nowrap;">
                    {{ $course->duration_label ?? ($course->duration_minutes ? intdiv($course->duration_minutes, 60).'h '.($course->duration_minutes % 60).'m' : '—') }}
                </td>
                <td class="td font-mono text-xs">{{ $course->enrollments_count }}</td>
                <td class="td">
                    @if ($course->trashed())
                        <x-badge variant="danger">Trashed</x-badge>
                    @else
                        <x-badge :variant="match($course->status) { 'published' => 'success', 'draft' => 'warning', default => 'neutral' }">{{ $course->status }}</x-badge>
                    @endif
                </td>
                <td class="td text-right">
                    <div class="flex items-center justify-end gap-1">
                        @if ($course->trashed())
                            @can('courses.restore')
                                <x-confirm-form :action="route('admin.courses.restore', $course)" method="POST" variant="primary"
                                    title="Restore course" :message="'Restore '.$course->title.'?'" confirm-label="Restore"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                    <x-icon name="restore" class="size-4" />
                                </x-confirm-form>
                            @endcan
                            @can('courses.delete')
                                <x-confirm-form :action="route('admin.courses.force-destroy', $course)" method="DELETE"
                                    title="Delete forever" :message="'Permanently delete '.$course->title.'? All curriculum data is lost.'" confirm-label="Delete forever"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                    <x-icon name="trash" class="size-4" />
                                </x-confirm-form>
                            @endcan
                        @else
                            <a href="{{ route('admin.courses.show', $course) }}" title="Course builder" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                <x-icon name="eye" class="size-4" />
                            </a>
                            @can('courses.update')
                                <a href="{{ route('admin.courses.edit', $course) }}" aria-label="Edit course" title="Edit course" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                    <x-icon name="pencil" class="size-4" />
                                </a>
                            @endcan
                            @can('courses.delete')
                                <x-confirm-form :action="route('admin.courses.destroy', $course)" method="DELETE"
                                    title="Move to trash" :message="'Move '.$course->title.' to trash? Students lose access until restored.'" confirm-label="Move to trash"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                    <x-icon name="trash" class="size-4" />
                                </x-confirm-form>
                            @endcan
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8">
                    <x-empty-state icon="academic-cap" title="No courses found" description="Try different filters, or create a new course." />
                </td>
            </tr>
        @endforelse
    </tbody>
    <x-slot:footer>
        <div data-pagination>{{ $courses->links() }}</div>
    </x-slot:footer>
</x-table>
