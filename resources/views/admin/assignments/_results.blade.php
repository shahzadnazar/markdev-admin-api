<x-table>
    <thead class="bg-surface-ice/60">
        <tr>
            <th class="th">Assignment</th>
            <th class="th">Course</th>
            <th class="th">Due</th>
            <th class="th">Max score</th>
            <th class="th">Submissions</th>
            <th class="th text-right">Actions</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($assignments as $assignment)
            <tr class="row">
                <td class="td">
                    <a href="{{ route('admin.assignments.submissions', $assignment) }}" class="font-medium text-on-surface hover:text-primary">{{ $assignment->title }}</a>
                    @if ($assignment->lesson)
                        <p class="text-xs text-outline">Lesson: {{ $assignment->lesson->title }}</p>
                    @endif
                </td>
                <td class="td max-w-[16rem]"><p class="truncate text-on-surface-variant">{{ $assignment->course?->title }}</p></td>
                <td class="td">
                    @if ($assignment->due_at)
                        <span class="font-mono text-xs {{ $assignment->due_at->isPast() ? 'text-error' : 'text-on-surface-variant' }}">{{ $assignment->due_at->format('j M Y H:i') }}</span>
                    @else
                        <span class="text-xs text-outline">No deadline</span>
                    @endif
                </td>
                <td class="td font-mono text-xs">{{ $assignment->max_score }}</td>
                <td class="td">
                    <div class="flex items-center gap-2">
                        <x-badge variant="primary">{{ $assignment->submissions_count }}</x-badge>
                        @if ($assignment->ungraded_count > 0)
                            <x-badge variant="warning">{{ $assignment->ungraded_count }} to grade</x-badge>
                        @endif
                    </div>
                </td>
                <td class="td">
                    <div class="flex items-center justify-end gap-1">
                        <a href="{{ route('admin.assignments.submissions', $assignment) }}" title="Submissions" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                            <x-icon name="inbox" class="size-4" />
                        </a>
                        @can('assignments.update')
                            <a href="{{ route('admin.assignments.edit', $assignment) }}" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                <x-icon name="pencil" class="size-4" />
                            </a>
                        @endcan
                        @can('assignments.delete')
                            <x-confirm-form :action="route('admin.assignments.destroy', $assignment)" method="DELETE"
                                title="Delete assignment" :message="'Delete '.$assignment->title.' and its submissions?'" confirm-label="Delete"
                                class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                <x-icon name="trash" class="size-4" />
                            </x-confirm-form>
                        @endcan
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6">
                    <x-empty-state icon="clipboard" title="No assignments found" description="Create the first assignment for a course." />
                </td>
            </tr>
        @endforelse
    </tbody>
    <x-slot:footer>
        <div data-pagination>{{ $assignments->links() }}</div>
    </x-slot:footer>
</x-table>
