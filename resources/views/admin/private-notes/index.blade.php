<x-admin.layout title="Private notes">
    <x-page-header eyebrow="Oversight" title="Student private notes"
        description="Who is keeping notes, and where. Opening one is recorded in the audit log." />

    {{-- Said plainly, at the top, because a power nobody can see is the one
         that gets misused — and the person using it should know it is seen. --}}
    <div class="mb-5 max-w-3xl rounded-xl border border-warning/30 bg-warning-container/30 px-4 py-3">
        <p class="text-sm font-medium text-on-surface">These are students' own notes.</p>
        <p class="mt-1 text-xs text-on-surface-variant">
            This list shows that a note exists, never what it says. Opening one writes an audit entry naming you,
            the student and the lesson. Nothing here can be edited or deleted — not by you, not by anyone.
        </p>
    </div>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Student</th>
                <th class="th">Lesson</th>
                <th class="th">Course</th>
                <th class="th">Last written</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($notes as $note)
                <tr class="row">
                    <td class="td">
                        <p class="font-medium text-on-surface">{{ $note->user?->name ?? 'Deleted user' }}</p>
                        <p class="text-xs text-outline">{{ $note->user?->email }}</p>
                    </td>
                    <td class="td max-w-[18rem]"><p class="truncate text-on-surface-variant">{{ $note->lesson?->title ?? '—' }}</p></td>
                    <td class="td max-w-[16rem]"><p class="truncate text-on-surface-variant">{{ $note->lesson?->course?->title ?? '—' }}</p></td>
                    <td class="td font-mono text-xs text-outline">{{ $note->updated_at?->format('j M Y, g:i A') }}</td>
                    <td class="td">
                        <div class="flex items-center justify-end">
                            <x-btn variant="secondary" size="sm" :href="route('admin.private-notes.show', $note)">
                                <x-icon name="eye" class="size-3.5" /> Open
                            </x-btn>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><x-empty-state icon="document" title="No notes yet" description="Notes appear here once students start writing them." /></td></tr>
            @endforelse
        </tbody>
        @if ($notes->hasPages())
            <x-slot:footer>{{ $notes->links() }}</x-slot:footer>
        @endif
    </x-table>
</x-admin.layout>
