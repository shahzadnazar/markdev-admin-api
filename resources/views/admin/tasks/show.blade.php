{{-- The project is named by NAME and CODE. Its client and its contract value
     are never loaded here, so neither can reach the page through an eager
     load, a breadcrumb or a careless reference. --}}
<x-admin.layout :title="$task->title">
    <x-page-header eyebrow="Team" :title="$task->title"
        :description="$task->description ?: 'No description.'"
        :crumbs="['Tasks' => route('admin.tasks.index'), $task->title => null]">
        <x-slot:actions>
            @can('tasks.create')
                <x-btn variant="secondary" :href="route('admin.tasks.assign', $task)">
                    <x-icon name="user-plus" class="size-4" /> {{ $task->openAssignment ? 'Hand over' : 'Assign' }}
                </x-btn>
                <x-btn :href="route('admin.tasks.edit', $task)">
                    <x-icon name="pencil" class="size-4" /> Edit task
                </x-btn>
            @endcan
            <x-btn variant="ghost" :href="route('admin.tasks.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to tasks
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
                            <span class="size-2.5 shrink-0 rounded-full ring-1 ring-inset ring-black/10" style="background-color: {{ $task->status?->colour }}"></span>
                            {{ $task->status?->label }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-outline">Team</dt>
                        <dd class="mt-0.5 text-on-surface">{{ $task->team?->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-outline">Work</dt>
                        <dd class="mt-0.5 text-on-surface">
                            @if ($task->project)
                                <span class="font-mono text-[12px]">{{ $task->project->code }}</span> · {{ $task->project->name }}
                            @else
                                Internal — no client
                            @endif
                        </dd>
                    </div>
                    @if ($task->parent)
                        <div>
                            <dt class="text-outline">Part of</dt>
                            <dd class="mt-0.5 text-on-surface">
                                <a href="{{ route('admin.tasks.show', $task->parent) }}" class="hover:text-primary">{{ $task->parent->title }}</a>
                            </dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-outline">Days allowed</dt>
                        <dd class="mt-0.5 font-mono font-semibold text-on-surface">{{ $task->days_allowed }}</dd>
                    </div>
                    <div>
                        <dt class="text-outline">Due</dt>
                        <dd class="mt-0.5 font-mono text-on-surface">{{ $task->due_date?->format('j M Y') ?: '—' }}</dd>
                    </div>
                </dl>

                @can('tasks.update')
                    {{-- Moving to a blocked status asks for the reason first.
                         The same endpoint the board drops onto, so the rule is
                         enforced once in TaskWorkflow and not twice here. --}}
                    <form method="POST" action="{{ route('admin.tasks.move', $task) }}" class="mt-5 border-t border-surface-ice pt-4"
                        x-data="{ target: '', blocked: @js($statuses->where('behaviour', 'blocked')->pluck('id')->map(fn ($id) => (string) $id)->values()) }">
                        @csrf
                        <x-form.select label="Move to" name="task_status_id" x-model="target">
                            <option value="">Pick a status</option>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->id }}">{{ $status->label }}</option>
                            @endforeach
                        </x-form.select>
                        <div x-cloak x-show="blocked.includes(target)" class="mt-3">
                            <x-form.textarea label="Why is it blocked?" name="reason" rows="3"
                                hint="Recorded against the task and audited. Parking a task stops its clock, so the reason is part of the record." />
                        </div>
                        <x-btn class="mt-3 w-full" size="sm">
                            <x-icon name="check" class="size-4" /> Move
                        </x-btn>
                    </form>
                @endcan
            </x-card>

            @php $split = $task->splitSummary(); @endphp
            @if ($split)
                <x-card>
                    <h2 class="font-display text-[15px] font-semibold text-on-surface">The split</h2>
                    {{-- Plain fact, derived, stored nowhere. Parts are ALLOWED
                         to total more or less than the parent; this is the
                         lead's planning signal and never reaches a member's
                         score, which is computed on their own stint. --}}
                    {{-- One line, because the sentence is the deliverable: a
                         lead reads it whole, and a test asserts it whole. --}}
                    <p class="mt-1 text-[13px] leading-5 text-on-surface-variant">Split into {{ $split['parts'] }} part{{ $split['parts'] === 1 ? '' : 's' }} totalling {{ $split['allowed'] }} day{{ $split['allowed'] === 1 ? '' : 's' }} against {{ $split['promised'] }} allowed.</p>
                </x-card>
            @endif
        </div>

        <div class="space-y-5">
            <x-card>
                <h2 class="font-display text-[15px] font-semibold text-on-surface">Stints</h2>
                <p class="mt-0.5 text-[13px] text-on-surface-variant">
                    One row per person per period of responsibility. A handover closes a stint and opens another;
                    a reopen leaves the finished one exactly as it was.
                </p>

                <div class="mt-4 space-y-2">
                    @forelse ($task->assignments as $stint)
                        <div class="rounded-xl border border-outline-variant/60 px-4 py-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-medium text-on-surface">{{ $stint->user?->name }}</p>
                                <x-badge :variant="match ($stint->outcome) {
                                    'early', 'on_time' => 'success',
                                    'late' => 'danger',
                                    'handed_over' => 'warning',
                                    default => 'neutral',
                                }">{{ $stint->outcomeLabel() }}</x-badge>
                            </div>
                            <p class="mt-1 font-mono text-[11px] text-outline">
                                {{ $stint->days_allowed }} day{{ $stint->days_allowed === 1 ? '' : 's' }} allowed ·
                                {{ $clock->daysTaken($stint) }} taken ·
                                {{ $clock->blockedDays($stint) }} blocked ·
                                {{ $stint->started_on?->format('j M') }}–{{ $stint->ended_on?->format('j M Y') ?? 'open' }}
                            </p>
                        </div>
                    @empty
                        <p class="text-[13px] text-on-surface-variant">Nobody holds this task yet.</p>
                    @endforelse
                </div>
            </x-card>

            @if ($task->parts->isNotEmpty())
                <x-card>
                    <h2 class="font-display text-[15px] font-semibold text-on-surface">Parts</h2>
                    <div class="mt-4 space-y-2">
                        @foreach ($task->parts as $part)
                            <a href="{{ route('admin.tasks.show', $part) }}" class="flex items-center justify-between gap-3 rounded-xl border border-outline-variant/60 px-4 py-3 transition hover:border-primary/40">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-on-surface">{{ $part->title }}</span>
                                    <span class="mt-0.5 block font-mono text-[11px] text-outline">
                                        {{ $part->days_allowed }} day{{ $part->days_allowed === 1 ? '' : 's' }} ·
                                        {{ $part->openAssignment?->user?->name ?? 'Unassigned' }}
                                    </span>
                                </span>
                                <span class="shrink-0 rounded-full px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-[0.08em]"
                                    style="background-color: {{ $part->status?->colour }}1a; color: {{ $part->status?->colour }}">{{ $part->status?->label }}</span>
                            </a>
                        @endforeach
                    </div>
                </x-card>
            @endif

            <x-team.file-list :owner="$task" owner-type="task" :files="$task->files" />

            {{-- The conversation, kept attached to the work. Never
                 client-visible, and there is no flag that could change it. --}}
            <x-card>
                <h2 class="font-display text-[15px] font-semibold text-on-surface">Comments</h2>
                <p class="mt-0.5 text-[13px] text-on-surface-variant">Visible to whoever can see this task.</p>
            </x-card>

            <x-team.comment-thread
                :threads="$task->comments->whereNull('parent_id')"
                :store-route="route('admin.tasks.comments.store', $task)"
                :update-route="fn ($c) => route('admin.tasks.comments.update', [$task, $c])"
                :destroy-route="fn ($c) => route('admin.tasks.comments.destroy', [$task, $c])"
                placeholder="Comment on this task…"
                empty-title="No comments yet" />

            <x-card>
                <h2 class="font-display text-[15px] font-semibold text-on-surface">Status history</h2>
                <p class="mt-0.5 text-[13px] text-on-surface-variant">Why the clock stopped, and who stopped it.</p>
                <div class="mt-4 space-y-2">
                    @foreach ($task->statusPeriods as $period)
                        <div class="rounded-xl border border-outline-variant/60 px-4 py-3">
                            <p class="text-sm text-on-surface">
                                {{ $period->status?->label }}
                                <span class="font-mono text-[11px] text-outline">
                                    {{ $period->started_on?->format('j M Y') }} – {{ $period->ended_on?->format('j M Y') ?? 'now' }}
                                </span>
                            </p>
                            @if ($period->reason)
                                <p class="mt-1 text-[13px] leading-5 text-on-surface-variant">{{ $period->reason }}</p>
                                <p class="mt-0.5 font-mono text-[11px] text-outline">— {{ $period->changedBy?->name ?? 'System' }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-card>
        </div>
    </div>
</x-admin.layout>
