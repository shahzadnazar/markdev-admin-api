<x-table>
    <thead class="bg-surface-ice/60">
        <tr>
            <th class="th">Announcement</th>
            <th class="th">Audience</th>
            <th class="th">Author</th>
            <th class="th">Published</th>
            <th class="th">Reads</th>
            <th class="th text-right">Actions</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($announcements as $announcement)
            <tr class="row">
                <td class="td max-w-[22rem]">
                    <div class="flex items-center gap-2">
                        @if ($announcement->is_pinned)
                            <x-icon name="sparkles" class="size-4 shrink-0 text-primary" />
                        @endif
                        <p class="truncate font-medium text-on-surface">{{ $announcement->title }}</p>
                    </div>
                </td>
                <td class="td">
                    @if ($announcement->course)
                        <x-badge variant="primary">{{ Str::limit($announcement->course->title, 28) }}</x-badge>
                    @else
                        <x-badge variant="neutral">everyone</x-badge>
                    @endif
                </td>
                <td class="td text-sm text-on-surface-variant">{{ $announcement->author?->name ?? '—' }}</td>
                <td class="td font-mono text-xs text-outline">
                    {{ $announcement->published_at?->format('j M Y · H:i') ?? 'Draft' }}
                </td>
                <td class="td font-mono text-xs text-on-surface-variant">{{ $announcement->reads_count ?? 0 }}</td>
                <td class="td text-right">
                    <div class="inline-flex items-center gap-1">
                        @can('announcements.update')
                            <x-btn variant="ghost" size="sm" :href="route('admin.announcements.edit', $announcement)" aria-label="Edit announcement" title="Edit announcement">
                                <x-icon name="pencil" class="size-4" />
                            </x-btn>
                        @endcan
                        @can('announcements.delete')
                            <x-confirm-form
                                :action="route('admin.announcements.destroy', $announcement)"
                                method="DELETE"
                                title="Delete this announcement?"
                                message="Students will no longer see it in their feed."
                                confirm-label="Delete"
                                class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error"
                                aria-label="Delete announcement"
                            >
                                <x-icon name="trash" class="size-4" />
                            </x-confirm-form>
                        @endcan
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="6"><x-empty-state icon="megaphone" title="No announcements" description="Post your first update — pinned announcements stay on top for students." /></td></tr>
        @endforelse
    </tbody>
    @if ($announcements->hasPages())
        <x-slot:footer><div data-pagination>{{ $announcements->links() }}</div></x-slot:footer>
    @endif
</x-table>
