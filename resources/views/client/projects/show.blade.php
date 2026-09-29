{{-- One project, as its client sees it.

     Milestones and files are already filtered to `is_client_visible` by
     ClientPortal — an unflagged row is ABSENT from $milestones and $files, not
     merely unrendered — so there is no flag to check here and no way to forget
     to check one. --}}
<x-client.layout :title="$project->name" :client="$client">
    <x-page-header eyebrow="Client Portal" :title="$project->name"
        :description="$project->description ?: null"
        :crumbs="['Your projects' => route('client.projects.index'), $project->code => null]">
        <x-slot:meta>
            <span class="rounded-full bg-surface-ice px-2.5 py-0.5 font-mono text-[11px] font-semibold text-on-surface-variant">{{ $project->code }}</span>
        </x-slot:meta>
    </x-page-header>

    <x-form.errors-summary />

    <div class="grid gap-5 lg:grid-cols-[280px_minmax(0,1fr)]">
        <div class="space-y-5">
            <x-card>
                <h2 class="font-display text-[15px] font-semibold text-on-surface">Where it stands</h2>
                <dl class="mt-4 space-y-3 text-[13px]">
                    <div>
                        <dt class="text-outline">Status</dt>
                        <dd class="mt-0.5 inline-flex items-center gap-2 text-on-surface">
                            <span class="size-2.5 shrink-0 rounded-full ring-1 ring-inset ring-black/10" style="background-color: {{ $project->status?->colour }}"></span>
                            {{ $project->status?->label }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-outline">Started</dt>
                        <dd class="mt-0.5 font-mono text-on-surface">{{ $project->start_date?->format('j M Y') ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-outline">Due</dt>
                        <dd class="mt-0.5 font-mono text-on-surface">{{ $project->due_date?->format('j M Y') ?: '—' }}</dd>
                    </div>
                </dl>
            </x-card>
        </div>

        <div class="space-y-5">
            {{-- Milestones: the due date and whether it is done. How a client
                 learns progress, with nothing about who did it or how long it
                 took. --}}
            <x-card>
                <h2 class="font-display text-[15px] font-semibold text-on-surface">Milestones</h2>
                <p class="mt-0.5 text-[13px] text-on-surface-variant">The checkpoints this project is delivered in.</p>

                <div class="mt-4 space-y-2">
                    @forelse ($milestones as $milestone)
                        <div class="flex items-center justify-between gap-3 rounded-xl border border-outline-variant/60 px-4 py-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <x-icon :name="$milestone->isComplete() ? 'check' : 'clock'"
                                    class="size-4 shrink-0 {{ $milestone->isComplete() ? 'text-success' : 'text-outline' }}" />
                                <p class="truncate text-sm font-medium text-on-surface">{{ $milestone->name }}</p>
                            </div>
                            <p class="shrink-0 font-mono text-[11px] text-outline">
                                @if ($milestone->isComplete())
                                    Done {{ $milestone->completed_on->format('j M Y') }}
                                @elseif ($milestone->due_date)
                                    Due {{ $milestone->due_date->format('j M Y') }}
                                @else
                                    No date yet
                                @endif
                            </p>
                        </div>
                    @empty
                        <p class="text-[13px] text-on-surface-variant">No milestones have been shared with you yet.</p>
                    @endforelse
                </div>
            </x-card>

            {{-- Files an admin has marked for you. Served through a signed link:
                 the signature says who is asking, and FileController still asks
                 whether the file is flagged AND the project is yours. --}}
            <x-card>
                <h2 class="font-display text-[15px] font-semibold text-on-surface">Files shared with you</h2>

                <div class="mt-4 space-y-2">
                    @forelse ($files as $file)
                        <div class="flex items-center justify-between gap-3 rounded-xl border border-outline-variant/60 px-4 py-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <x-icon :name="$file->isImage() ? 'photo' : 'document'" class="size-4 shrink-0 text-outline" />
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-on-surface">{{ $file->original_name }}</p>
                                    <p class="mt-0.5 font-mono text-[11px] text-outline">{{ $file->sizeLabel() }}</p>
                                </div>
                            </div>
                            <a href="{{ \App\Support\PrivateFiles::signedUrl('files.client', ['file' => $file->id], auth()->user()) }}"
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-primary transition hover:bg-primary/5">
                                <x-icon name="download" class="size-4" /> Download
                            </a>
                        </div>
                    @empty
                        <p class="text-[13px] text-on-surface-variant">Nothing has been shared with you on this project yet.</p>
                    @endforelse
                </div>
            </x-card>

            {{-- Questions. One question, one answer — not a conversation. --}}
            <x-card>
                <h2 class="font-display text-[15px] font-semibold text-on-surface">Questions</h2>
                <p class="mt-0.5 text-[13px] text-on-surface-variant">Ask the team about this project. They will reply here.</p>

                <form method="POST" action="{{ route('client.questions.store', $project) }}" class="mt-4">
                    @csrf
                    <label for="body" class="sr-only">Your question</label>
                    <textarea id="body" name="body" rows="3" required maxlength="4000"
                        placeholder="Ask about this project…"
                        class="w-full rounded-xl border border-outline-variant/70 bg-white px-4 py-3 text-sm text-on-surface placeholder:text-outline focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/15">{{ old('body') }}</textarea>
                    <div class="mt-2 flex justify-end">
                        <x-btn type="submit" size="sm">Send question</x-btn>
                    </div>
                </form>

                <div class="mt-5 space-y-3">
                    @forelse ($questions as $question)
                        <div class="rounded-xl border border-outline-variant/60 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <p class="min-w-0 whitespace-pre-line text-[13px] leading-5 text-on-surface">{{ $question->body }}</p>
                                <x-badge :variant="match ($question->status) {
                                    \App\Models\ClientQuestion::ANSWERED => 'success',
                                    \App\Models\ClientQuestion::CLOSED => 'neutral',
                                    default => 'warning',
                                }" class="shrink-0">{{ $question->statusLabel() }}</x-badge>
                            </div>
                            <p class="mt-1.5 font-mono text-[10px] text-outline">Asked {{ $question->created_at->format('j M Y') }}</p>

                            @if ($question->answer_body)
                                {{-- "The team" and not a name: the lead writes it
                                     after talking to the team, and who typed it is
                                     not something a client needs. --}}
                                <div class="mt-3 rounded-lg bg-surface-ice px-4 py-3">
                                    <p class="font-mono text-[10px] font-medium uppercase tracking-[0.12em] text-primary">The team replied</p>
                                    <p class="mt-1 whitespace-pre-line text-[13px] leading-5 text-on-surface">{{ $question->answer_body }}</p>
                                    <p class="mt-1.5 font-mono text-[10px] text-outline">{{ $question->answered_at?->format('j M Y') }}</p>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-[13px] text-on-surface-variant">You have not asked anything yet.</p>
                    @endforelse
                </div>
            </x-card>
        </div>
    </div>
</x-client.layout>
