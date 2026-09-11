<x-admin.layout :title="'Private notes — '.($note->user?->name ?? 'student')">
    <x-page-header
        eyebrow="Oversight"
        :title="($note->user?->name ?? 'Deleted user').'’s notes'"
        :description="($note->lesson?->title ?? 'Lesson').' · '.($note->lesson?->course?->title ?? 'Course')"
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Private notes' => route('admin.private-notes.index'), ($note->user?->name ?? 'Note') => null]"
    >
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.private-notes.index')">
                <x-icon name="arrow-left" class="size-4" /> Back
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    {{-- Stated on the page that does the reading, not only on the list. --}}
    <div class="mb-5 max-w-3xl rounded-xl border border-warning/30 bg-warning-container/30 px-4 py-3">
        <p class="text-xs text-on-surface-variant">
            This read has been recorded in the audit log against your name, {{ $note->user?->name ?? 'the student' }}
            and this lesson. Notes are read-only here — there is no way to change or remove a student's own writing.
        </p>
    </div>

    <x-card class="max-w-3xl">
        <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-surface-ice pb-3">
            <p class="eyebrow">Written by the student</p>
            <p class="font-mono text-xs text-outline">Last saved {{ $note->updated_at?->format('j M Y, g:i A') }}</p>
        </div>
        {{-- Escaped and wrapped, never rendered as markup: this is text a
             student typed, and it arrives here unfiltered. --}}
        <p class="mt-4 whitespace-pre-line text-sm leading-6 text-on-surface">{{ $note->body }}</p>
    </x-card>
</x-admin.layout>
