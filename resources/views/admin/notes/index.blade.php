<x-admin.layout title="Notes">

    <x-page-header
        eyebrow="Learning"
        title="Notes"
        description="Manage course notes and learning materials."
    >
        <x-slot:actions>
            @can('notes.create')
                <x-btn :href="route('admin.notes.create')">
                    <x-icon name="plus" class="size-4" />
                    Upload note
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar :action="route('admin.notes.index')">

        <div class="w-full sm:w-60">
            <x-form.label for="search" value="Search" />
            <input
                type="search"
                name="search"
                id="search"
                value="{{ request('search') }}"
                placeholder="Note title…"
                class="field"
            >
        </div>

        <div class="w-full sm:w-60">
            <x-form.label for="course" value="Course" />

            <select name="course" id="course" class="field">
                <option value="">All courses</option>

                @foreach ($courses as $course)
                    <option
                        value="{{ $course->id }}"
                        @selected(request('course') == $course->id)
                    >
                        {{ $course->title }}
                    </option>
                @endforeach
            </select>
        </div>

    </x-filter-bar>

    <x-table>

        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Note</th>
                <th class="th">Course</th>
                <th class="th">Instructor</th>
                <th class="th">File type</th>
                <th class="th">Size</th>
                <th class="th">Uploaded</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>

        <tbody>

            @forelse ($notes as $note)

                <tr class="row">

                    {{-- Note --}}
                    <td class="td">
                        <div class="flex items-center gap-3">

                            <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-primary/15 to-secondary/15 text-primary">
                                <x-icon name="document-text" class="size-5" />
                            </div>

                            <div class="min-w-0">
                                <p class="max-w-[18rem] truncate font-medium text-on-surface">
                                    {{ $note->title }}
                                </p>

                                @if ($note->description)
                                    <p class="max-w-[20rem] truncate text-xs text-outline">
                                        {{ $note->description }}
                                    </p>
                                @endif
                            </div>

                        </div>
                    </td>

                    {{-- Course --}}
                    <td class="td text-on-surface-variant">
                        {{ $note->course?->title ?? '—' }}
                    </td>

                    {{-- Instructor --}}
                    <td class="td text-on-surface-variant">
                        {{ $note->instructor?->name ?? '—' }}
                    </td>

                    {{-- File type --}}
                    <td class="td">
                        <x-badge variant="secondary">
                            {{ strtoupper(pathinfo($note->file_path ?? '', PATHINFO_EXTENSION) ?: 'FILE') }}
                        </x-badge>
                    </td>

                    {{-- Size --}}
                    <td class="td font-mono text-xs">
                        @if ($note->size_bytes)
                            {{ number_format($note->size_bytes / 1024, 1) }} KB
                        @else
                            —
                        @endif
                    </td>

                    {{-- Date --}}
                    <td class="td font-mono text-xs">
                        {{ $note->created_at?->format('M d, Y') ?? '—' }}
                    </td>

                    {{-- Actions --}}
                    <td class="td text-right">

                        <div class="flex items-center justify-end gap-1">

                            {{-- Download --}}
                            <a
                                href="{{ route('admin.notes.download', $note) }}"
                                title="Download note"
                                class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary"
                            >
                                <x-icon name="download" class="size-4" />
                            </a>

                            {{-- Edit --}}
                            @can('notes.update')
                                <a
                                    href="{{ route('admin.notes.edit', $note) }}"
                                    title="Edit note"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary"
                                >
                                    <x-icon name="pencil" class="size-4" />
                                </a>
                            @endcan

                            {{-- Delete --}}
                            @can('notes.delete')
                                <x-confirm-form
                                    :action="route('admin.notes.destroy', $note)"
                                    method="DELETE"
                                    title="Move note to trash"
                                    :message="'Move '.$note->title.' to trash?'"
                                    confirm-label="Move to trash"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error"
                                >
                                    <x-icon name="trash" class="size-4" />
                                </x-confirm-form>
                            @endcan

                        </div>

                    </td>

                </tr>

            @empty

                <tr>
                    <td colspan="7">
                        <x-empty-state
                            icon="document-text"
                            title="No notes found"
                            description="Try different filters, or upload a new note."
                        />
                    </td>
                </tr>

            @endforelse

        </tbody>

        <x-slot:footer>
            {{ $notes->links() }}
        </x-slot:footer>

    </x-table>

</x-admin.layout>