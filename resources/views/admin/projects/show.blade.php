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
</x-admin.layout>
