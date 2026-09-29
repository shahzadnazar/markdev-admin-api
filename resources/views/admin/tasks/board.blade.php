{{-- The board. Columns ARE the active task statuses, in their sort order, so
     an admin who adds "Waiting on client" gets a column with no code change.

     Dropping into a BLOCKED column asks for the reason before it commits. The
     drop posts to the same move endpoint the task page uses — the rule lives
     in TaskWorkflow and is enforced once, not once per surface.

     No client and no money anywhere: the project is a code and a name. --}}
<x-admin.layout title="Board">
    <x-page-header eyebrow="Team" title="Board"
        :description="$team ? $team->name.' — drag a card to move it.' : 'No team to show yet.'"
        :crumbs="['Tasks' => route('admin.tasks.index'), 'Board' => null]">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.tasks.index')">
                <x-icon name="arrow-left" class="size-4" /> Task list
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <x-form.errors-summary />

    @if ($teams->count() > 1)
        <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
            <x-form.select label="Team" name="team" class="w-56">
                @foreach ($teams as $option)
                    <option value="{{ $option->id }}" @selected($team && $team->id === $option->id)>{{ $option->name }}</option>
                @endforeach
            </x-form.select>
            <x-btn variant="secondary" size="md" type="submit">Show</x-btn>
        </form>
    @endif

    @php $blockedIds = $statuses->where('behaviour', 'blocked')->pluck('id')->map(fn ($id) => (string) $id)->values(); @endphp

    <div x-data="{
            dragging: null,
            blocked: @js($blockedIds),
            pending: null,
            reason: '',
            drop(statusId) {
                if (this.dragging === null) return;
                const taskId = this.dragging;
                this.dragging = null;
                if (this.blocked.includes(String(statusId))) {
                    // A blocked column stops somebody's clock. Ask first, and
                    // let them cancel — the server refuses a blank reason too.
                    this.pending = { taskId, statusId };
                    this.reason = '';
                    return;
                }
                this.submit(taskId, statusId, '');
            },
            submit(taskId, statusId, reason) {
                const form = document.getElementById('board-move-form');
                form.action = form.dataset.template.replace('__TASK__', taskId);
                form.querySelector('[name=task_status_id]').value = statusId;
                form.querySelector('[name=reason]').value = reason;
                form.submit();
            },
        }"
        class="flex gap-4 overflow-x-auto pb-4 scroll-thin">

        <form id="board-move-form" method="POST" action="" class="hidden"
            data-template="{{ route('admin.tasks.move', ['task' => '__TASK__']) }}">
            @csrf
            <input type="hidden" name="task_status_id" value="">
            <input type="hidden" name="reason" value="">
        </form>

        @foreach ($statuses as $status)
            <section class="flex w-72 shrink-0 flex-col rounded-2xl bg-surface-ice/70 p-3"
                x-on:dragover.prevent
                x-on:drop.prevent="drop('{{ $status->id }}')">
                <header class="mb-3 flex items-center gap-2 px-1">
                    <span class="size-2.5 shrink-0 rounded-full ring-1 ring-inset ring-black/10" style="background-color: {{ $status->colour }}"></span>
                    <h2 class="font-display text-[13px] font-semibold text-on-surface">{{ $status->label }}</h2>
                    <span class="ml-auto font-mono text-[11px] text-outline">{{ $columns[$status->id]->count() }}</span>
                </header>

                <div class="space-y-2">
                    @forelse ($columns[$status->id] as $task)
                        <article draggable="true"
                            x-on:dragstart="dragging = '{{ $task->id }}'"
                            class="cursor-grab rounded-xl border border-outline-variant/60 bg-white px-3 py-2.5 shadow-sm transition hover:border-primary/40 active:cursor-grabbing">
                            <a href="{{ route('admin.tasks.show', $task) }}" class="block text-[13px] font-medium text-on-surface hover:text-primary">{{ $task->title }}</a>
                            <p class="mt-1 font-mono text-[10px] text-outline">
                                @if ($task->project){{ $task->project->code }}@else Internal @endif
                                · {{ $task->days_allowed }}d
                            </p>
                            <p class="mt-0.5 truncate text-[11px] text-on-surface-variant">{{ $task->openAssignment?->user?->name ?? 'Unassigned' }}</p>
                        </article>
                    @empty
                        <p class="px-1 py-6 text-center text-[11px] text-outline">Nothing here</p>
                    @endforelse
                </div>
            </section>
        @endforeach

        {{-- The reason prompt. Nothing is committed until it is filled in. --}}
        <template x-teleport="body">
            <div x-cloak x-show="pending !== null" class="fixed inset-0 z-50 flex items-center justify-center bg-on-surface/40 px-4">
                <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-elevated" x-on:click.outside="pending = null">
                    <h2 class="font-display text-lg font-semibold text-on-surface">Why is it blocked?</h2>
                    <p class="mt-1 text-[13px] leading-5 text-on-surface-variant">
                        Parking a task stops the holder's clock, so the reason is recorded against it and audited.
                        Blocked days are counted and shown — parking is visible, not forbidden.
                    </p>
                    <textarea x-model="reason" rows="4" class="field mt-4" placeholder="Waiting on the client's copy"></textarea>
                    <div class="mt-4 flex items-center justify-end gap-2.5">
                        <x-btn variant="ghost" size="sm" type="button" x-on:click="pending = null">Cancel</x-btn>
                        <x-btn size="sm" type="button"
                            x-bind:disabled="reason.trim() === ''"
                            x-on:click="submit(pending.taskId, pending.statusId, reason)">Block it</x-btn>
                    </div>
                </div>
            </div>
        </template>
    </div>
</x-admin.layout>
