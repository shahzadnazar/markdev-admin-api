{{-- THE OTHER SHARED SCREEN. Same rule as the index: the client and the money
     are inside @can('clients.view'), and the controller does not load the
     client at all for a viewer who fails it. A team person reaching a project
     outside their teams gets a 404 before this renders — see
     ProjectController::assertVisible for why not a 403. --}}
<x-admin.layout :title="$project->name">
    <x-page-header eyebrow="Team" :title="$project->name"
        :description="$project->description ?: 'No description.'"
        :crumbs="['Projects' => route('admin.projects.index'), $project->code => null]">
        <x-slot:meta>
            <span class="rounded-full bg-surface-ice px-2.5 py-0.5 font-mono text-[11px] font-semibold text-on-surface-variant">{{ $project->code }}</span>
        </x-slot:meta>
        <x-slot:actions>
            @can('projects.update')
                <x-btn :href="route('admin.projects.edit', $project)">
                    <x-icon name="pencil" class="size-4" /> Edit project
                </x-btn>
            @endcan
            <x-btn variant="ghost" :href="route('admin.projects.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to projects
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <x-form.errors-summary />

    <div class="grid gap-5 lg:grid-cols-[320px_minmax(0,1fr)]">
        <div class="space-y-5">
            <x-card>
                <h2 class="font-display text-[15px] font-semibold text-on-surface">Details</h2>
                <dl class="mt-4 space-y-3 text-[13px]">
                    <div>
                        <dt class="text-outline">Status</dt>
                        <dd class="mt-0.5 inline-flex items-center gap-2 text-on-surface">
                            <span class="size-2.5 shrink-0 rounded-full ring-1 ring-inset ring-black/10" style="background-color: {{ $project->status?->colour }}"></span>
                            {{ $project->status?->label }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-outline">Team</dt>
                        <dd class="mt-0.5 text-on-surface">{{ $project->team?->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-outline">Starts</dt>
                        <dd class="mt-0.5 font-mono text-on-surface">{{ $project->start_date?->format('j M Y') ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-outline">Schedule</dt>
                        <dd class="mt-0.5"><x-projects.days :project="$project" /></dd>
                    </div>

                    @can('clients.view')
                        {{-- Client and money. The gate is the point of this
                             screen's design: everything above is work, and
                             everything in here is commercial. --}}
                        <div class="border-t border-surface-ice pt-3">
                            <dt class="text-outline">Client</dt>
                            <dd class="mt-0.5 text-on-surface">
                                <a href="{{ route('admin.clients.show', $project->client) }}" class="hover:text-primary">{{ $project->client?->company ?: $project->client?->name }}</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-outline">Contract value</dt>
                            <dd class="mt-0.5 font-mono font-semibold text-on-surface">
                                @if ($project->contract_value !== null)
                                    {{ $project->currency }} {{ number_format((float) $project->contract_value, 2) }}
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                    @endcan
                </dl>
            </x-card>
        </div>

        <div class="space-y-5">
        <x-team.file-list :owner="$project" owner-type="project" :files="$project->files" />

        {{-- WHAT THE CLIENT ASKED. One question, one answer — the lead's
             DISCUSSION of it happens in the ordinary discussion below, which the
             client cannot see. There is no thread here and there must not be
             one: a client-visible conversation is what phase 5's whole table
             design keeps out. --}}
        @if ($project->questions->isNotEmpty())
            <x-card>
                <h2 class="font-display text-[15px] font-semibold text-on-surface">Client questions</h2>
                <p class="mt-0.5 text-[13px] text-on-surface-variant">
                    Only this project's team lead answers the client. Anyone else raises it in the discussion below.
                </p>

                <div class="mt-4 space-y-3">
                    @foreach ($project->questions as $question)
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
                                <div class="mt-3 rounded-lg bg-surface-ice px-4 py-3">
                                    <p class="font-mono text-[10px] font-medium uppercase tracking-[0.12em] text-primary">
                                        Answered by {{ $question->answerer?->name ?? 'the team' }}
                                    </p>
                                    <p class="mt-1 whitespace-pre-line text-[13px] leading-5 text-on-surface">{{ $question->answer_body }}</p>
                                </div>
                            @endif

                            {{-- The form is drawn for the lead and the admins only.
                                 A member who posts anyway gets a 403 from the
                                 controller — the gate is there, and this only
                                 stops offering a control that would refuse. --}}
                            @if ($question->isOpen() && $question->mayBeAnsweredBy(auth()->user()))
                                <form method="POST" action="{{ route('admin.projects.questions.answer', [$project, $question]) }}" class="mt-3">
                                    @csrf
                                    <label for="answer-{{ $question->id }}" class="sr-only">Your answer</label>
                                    <textarea id="answer-{{ $question->id }}" name="answer_body" rows="3" required maxlength="4000"
                                        placeholder="Write the answer the client will read…"
                                        class="w-full rounded-xl border border-outline-variant/70 bg-white px-4 py-3 text-sm text-on-surface placeholder:text-outline focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/15"></textarea>
                                    <div class="mt-2 flex justify-end">
                                        <x-btn type="submit" size="sm">Send answer</x-btn>
                                    </div>
                                </form>

                                {{-- Outside the answer form, not inside it: the
                                     confirm dialog carries its own <form>, and one
                                     form nested in another is a shape nobody should
                                     have to reason about. --}}
                                <div class="mt-2 flex justify-end">
                                    <x-confirm-form :action="route('admin.projects.questions.close', [$project, $question])" method="POST"
                                        title="Close without answering"
                                        message="Close this question without an answer? The client will see that it was closed, rather than being left waiting."
                                        confirm-label="Close it"
                                        variant="primary"
                                        class="px-3 py-1.5 text-xs font-medium text-on-surface-variant transition hover:bg-primary/5 hover:text-primary">
                                        Close without answering
                                    </x-confirm-form>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-card>
        @endif

        {{-- The team's discussion. A CLIENT NEVER SEES THIS, even on their own
             project, and there is no per-comment flag that could change that. --}}
        <x-card>
            <h2 class="font-display text-[15px] font-semibold text-on-surface">Discussion</h2>
            <p class="mt-0.5 text-[13px] text-on-surface-variant">Private to the team on this project.</p>
        </x-card>

        <x-team.comment-thread
            :threads="$project->comments->whereNull('parent_id')"
            :store-route="route('admin.projects.comments.store', $project)"
            :update-route="fn ($c) => route('admin.projects.comments.update', [$project, $c])"
            :destroy-route="fn ($c) => route('admin.projects.comments.destroy', [$project, $c])"
            placeholder="Talk to the team about this project…"
            empty-title="No discussion yet" />

        <x-card>
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="font-display text-[15px] font-semibold text-on-surface">Milestones</h2>
                    <p class="mt-0.5 text-[13px] text-on-surface-variant">The checkpoints this project is delivered in.</p>
                </div>
                @can('projects.update')
                    <x-btn variant="secondary" size="sm" :href="route('admin.projects.milestones.create', $project)">
                        <x-icon name="plus" class="size-4" /> Add milestone
                    </x-btn>
                @endcan
            </div>

            <div class="mt-4 space-y-2">
                @forelse ($project->milestones as $milestone)
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-outline-variant/60 px-4 py-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-icon :name="$milestone->isComplete() ? 'check' : 'clock'"
                                class="size-4 shrink-0 {{ $milestone->isComplete() ? 'text-success' : 'text-outline' }}" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-on-surface">{{ $milestone->name }}</p>
                                <p class="mt-0.5 font-mono text-[11px] text-outline">
                                    @if ($milestone->isComplete())
                                        Done {{ $milestone->completed_on->format('j M Y') }}
                                    @elseif ($milestone->due_date)
                                        Due {{ $milestone->due_date->format('j M Y') }}
                                    @else
                                        No date
                                    @endif
                                </p>
                            </div>
                        </div>
                        @can('projects.update')
                            <div class="flex shrink-0 items-center gap-1">
                                <form method="POST" action="{{ route('admin.projects.milestones.move', [$project, $milestone]) }}">
                                    @csrf <input type="hidden" name="direction" value="up">
                                    <button type="submit" @disabled($loop->first) class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary disabled:opacity-30" title="Move up">
                                        <x-icon name="arrow-up" class="size-4" />
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.projects.milestones.move', [$project, $milestone]) }}">
                                    @csrf <input type="hidden" name="direction" value="down">
                                    <button type="submit" @disabled($loop->last) class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary disabled:opacity-30" title="Move down">
                                        <x-icon name="arrow-down" class="size-4" />
                                    </button>
                                </form>
                                <a href="{{ route('admin.projects.milestones.edit', [$project, $milestone]) }}" aria-label="Edit milestone" title="Edit milestone" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                    <x-icon name="pencil" class="size-4" />
                                </a>
                                <x-confirm-form :action="route('admin.projects.milestones.destroy', [$project, $milestone])" method="DELETE"
                                    title="Delete milestone"
                                    :message="'Delete '.$milestone->name.'?'"
                                    confirm-label="Delete"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                    <x-icon name="trash" class="size-4" />
                                </x-confirm-form>
                            </div>
                        @endcan
                    </div>
                @empty
                    <p class="text-[13px] text-on-surface-variant">No milestones yet.</p>
                @endforelse
            </div>
        </x-card>
        </div>
    </div>
</x-admin.layout>
