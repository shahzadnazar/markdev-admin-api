{{-- Scoped by Task::visibleTo, the one row filter. The project is named by its
     NAME and CODE only — the controller does not fetch its client or its
     contract value, so there is nothing here to leak. --}}
<x-admin.layout title="Tasks">
    <x-page-header eyebrow="Team" title="Tasks"
        description="The work your teams are doing. A task on a project belongs to that project's team; work with no project is internal."
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Tasks' => null]">
        <x-slot:actions>
            <x-btn variant="secondary" :href="route('admin.tasks.board')">
                <x-icon name="dashboard" class="size-4" /> Board
            </x-btn>
            @can('tasks.create')
                <x-btn :href="route('admin.tasks.create')">
                    <x-icon name="plus" class="size-4" /> New task
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-form.errors-summary />

    @isset($myScore)
        {{-- The viewer's own score, computed LIVE. A page about one person
             never reads the cache; that is for lists. --}}
        <x-card class="mb-5">
            <p class="eyebrow mb-2">Your delivery</p>
            <x-team.delivery-score :score="$myScore" />
        </x-card>
    @endisset

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
        <x-form.select label="Team" name="team" class="w-48">
            <option value="">All teams</option>
            @foreach ($teams as $team)
                <option value="{{ $team->id }}" @selected((string) request('team') === (string) $team->id)>{{ $team->name }}</option>
            @endforeach
        </x-form.select>
        <x-form.select label="Status" name="status" class="w-48">
            <option value="">Any status</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->id }}" @selected((string) request('status') === (string) $status->id)>{{ $status->label }}</option>
            @endforeach
        </x-form.select>
        <x-btn variant="secondary" size="md" type="submit">
            <x-icon name="funnel" class="size-4" /> Filter
        </x-btn>
        <x-btn variant="ghost" size="md" :href="route('admin.tasks.index')">Clear</x-btn>
    </form>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Task</th>
                <th class="th">Team</th>
                <th class="th">Status</th>
                <th class="th">Holder</th>
                <th class="th td-num">Days</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($tasks as $task)
                <tr class="row">
                    <td class="td">
                        <a href="{{ route('admin.tasks.show', $task) }}" class="font-medium text-on-surface hover:text-primary">{{ $task->title }}</a>
                        <p class="mt-0.5 font-mono text-[11px] text-outline">
                            @if ($task->project)
                                {{ $task->project->code }} · {{ $task->project->name }}
                            @else
                                Internal
                            @endif
                            @if ($task->parts->isNotEmpty())
                                · {{ $task->parts->count() }} part{{ $task->parts->count() === 1 ? '' : 's' }}
                            @endif
                        </p>
                    </td>
                    <td class="td"><span class="text-sm text-on-surface-variant">{{ $task->team?->name }}</span></td>
                    <td class="td">
                        <span class="inline-flex items-center gap-2">
                            <span class="size-2.5 shrink-0 rounded-full ring-1 ring-inset ring-black/10" style="background-color: {{ $task->status?->colour }}"></span>
                            <span class="text-sm text-on-surface">{{ $task->status?->label }}</span>
                        </span>
                    </td>
                    <td class="td">
                        <span class="text-sm text-on-surface-variant">{{ $task->openAssignment?->user?->name ?? 'Unassigned' }}</span>
                    </td>
                    <td class="td td-num">
                        <span class="font-mono text-sm text-on-surface">{{ $task->days_allowed }}</span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">
                        <x-empty-state icon="clipboard" title="No tasks"
                            description="Nothing here yet. What you can see is the work your teams are doing — or, as a member, the work you hold." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-table>
</x-admin.layout>
