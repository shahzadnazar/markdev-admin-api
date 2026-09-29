{{-- The client's own projects, and nothing else on the screen.

     No team, no member, no task, no comment, no contract value, no currency.
     Those are not gated out here — the controller does not select or load them,
     so there is nothing for this file to accidentally reach for. --}}
<x-client.layout title="Your projects" :client="$client">
    <x-page-header eyebrow="Client Portal" title="Your projects"
        description="The work MarkDev is doing for you, and where each piece has got to." />

    @if ($projects->isEmpty())
        <x-card class="p-0">
            <x-empty-state icon="clipboard" title="No projects yet"
                description="When MarkDev starts a project for you it will appear here, with its milestones and the files shared with you." />
        </x-card>
    @else
        <div class="space-y-3">
            @foreach ($projects as $project)
                <a href="{{ route('client.projects.show', $project) }}"
                    class="block rounded-2xl bg-white p-5 shadow-card transition hover:-translate-y-px hover:shadow-elevated">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <h2 class="font-display text-[17px] font-semibold text-on-surface">{{ $project->name }}</h2>
                                <span class="rounded-full bg-surface-ice px-2.5 py-0.5 font-mono text-[11px] font-semibold text-on-surface-variant">{{ $project->code }}</span>
                            </div>
                            @if ($project->description)
                                <p class="mt-1 max-w-2xl text-[13px] leading-5 text-on-surface-variant">{{ $project->description }}</p>
                            @endif
                        </div>

                        {{-- The status label and its colour, which is the same
                             colour the team sees. A status is what a project IS,
                             not who is doing it. --}}
                        <span class="inline-flex shrink-0 items-center gap-2 rounded-full bg-surface-ice px-3 py-1 text-[12px] font-medium text-on-surface">
                            <span class="size-2.5 shrink-0 rounded-full ring-1 ring-inset ring-black/10" style="background-color: {{ $project->status?->colour }}"></span>
                            {{ $project->status?->label }}
                        </span>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 font-mono text-[11px] text-outline">
                        <span>Starts {{ $project->start_date?->format('j M Y') ?: '—' }}</span>
                        <span>Due {{ $project->due_date?->format('j M Y') ?: '—' }}</span>

                        {{-- PLAIN COUNTS, not an unread badge. "Answered since you
                             last looked" would need a per-client read marker, and
                             a read marker is the first piece of a notification
                             system — which this portal deliberately does not have. --}}
                        @if ($project->answered_questions_count > 0)
                            <span class="text-success">{{ $project->answered_questions_count }} answered</span>
                        @endif
                        @if ($project->open_questions_count > 0)
                            <span class="text-warning">{{ $project->open_questions_count }} awaiting a reply</span>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</x-client.layout>
