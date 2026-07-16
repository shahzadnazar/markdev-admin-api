<x-admin.layout title="Announcements">
    <x-page-header eyebrow="Engagement" title="Announcements" description="Broadcasts to the whole school or a single course.">
        <x-slot:actions>
            @can('announcements.create')
                <x-btn :href="route('admin.announcements.create')">
                    <x-icon name="plus" class="size-4" /> New announcement
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar :action="route('admin.announcements.index')">
        <div class="w-full sm:w-64">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Title…" class="field">
        </div>
        <div class="w-64">
            <x-form.label for="course" value="Course" />
            <select name="course" id="course" class="field">
                <option value="">All audiences</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected(request('course') == $course->id)>{{ $course->title }}</option>
                @endforeach
            </select>
        </div>
    </x-filter-bar>

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
                        {{ $announcement->published_at?->format('M j, Y · H:i') ?? 'Draft' }}
                    </td>
                    <td class="td font-mono text-xs text-on-surface-variant">{{ $announcement->reads_count ?? 0 }}</td>
                    <td class="td text-right">
                        <div class="inline-flex items-center gap-1">
                            @can('announcements.update')
                                <x-btn variant="ghost" size="sm" :href="route('admin.announcements.edit', $announcement)">
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
            <x-slot:footer>{{ $announcements->links() }}</x-slot:footer>
        @endif
    </x-table>
</x-admin.layout>
