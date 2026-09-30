<x-admin.layout :title="$task ? 'Edit task' : 'New task'">
    <x-page-header eyebrow="Team" :title="$task ? 'Edit '.$task->title : 'New task'"
        description="A task on a project is done by that project's team. Work with no project is internal — that is the only reason a project is optional."
        :crumbs="['Tasks' => route('admin.tasks.index'), ($task ? 'Edit' : 'New') => null]">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.tasks.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to tasks
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ $task ? route('admin.tasks.update', $task) : route('admin.tasks.store') }}" class="max-w-2xl">
        @csrf
        @if ($task) @method('PUT') @endif

        <x-form.errors-summary />

        <x-card class="space-y-5">
            <x-form.input label="Title" name="title" :value="$task?->title" required placeholder="e.g. Rebuild the checkout" />

            <x-form.textarea label="Description" name="description" rows="3" :value="$task?->description" />

            <div class="grid gap-5 border-t border-surface-ice pt-5 sm:grid-cols-2">
                {{-- The message has to be true for two different viewers. An
                     admin sees every active team and can make one; a team lead
                     sees only the teams they are on and cannot, so they get the
                     explanation without a link that would refuse them. --}}
                @if ($teams->isEmpty())
                    <x-form.prerequisite label="Team"
                        :message="auth()->user()->can('teams.create')
                            ? 'No team is available. A task belongs to exactly one, and the team\'s members are who can see it.'
                            : 'You are not on a team yet, so there is nothing to create a task against. An admin adds you to one.'"
                        :href="auth()->user()->can('teams.create') ? route('admin.teams.create') : null"
                        action="Add the first team" />
                @else
                    <x-form.select label="Team" name="team_id" required
                        hint="Who does the work. A task carries its own team, so internal work has a home and the board is one query.">
                        <option value="">Pick a team</option>
                        @foreach ($teams as $team)
                            <option value="{{ $team->id }}" @selected((string) old('team_id', $task?->team_id) === (string) $team->id)>{{ $team->name }}</option>
                        @endforeach
                    </x-form.select>
                @endif

                <x-form.select label="Project" name="project_id"
                    hint="Leave blank for internal work. A project's tasks belong to the project's team — another team's project is refused.">
                    <option value="">Internal — no project</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}" @selected((string) old('project_id', $task?->project_id) === (string) $project->id)>{{ $project->code }} — {{ $project->name }}</option>
                    @endforeach
                </x-form.select>
            </div>

            <div class="grid gap-5 border-t border-surface-ice pt-5 sm:grid-cols-2">
                <x-form.select label="Part of" name="parent_id"
                    hint="One level only. A part cannot be split again — a three-level tree has no answer to whose days a delay is against.">
                    <option value="">Not a part</option>
                    @foreach ($parents as $parent)
                        <option value="{{ $parent->id }}" @selected((string) old('parent_id', $task?->parent_id) === (string) $parent->id)>{{ $parent->title }}</option>
                    @endforeach
                </x-form.select>

                <x-form.select label="Status" name="task_status_id" required
                    hint="Only statuses on offer.">
                    <option value="">Pick a status</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->id }}" @selected((string) old('task_status_id', $task?->task_status_id) === (string) $status->id)>{{ $status->label }} · {{ $status->behaviourLabel() }}</option>
                    @endforeach
                </x-form.select>
            </div>

            <div class="grid gap-5 border-t border-surface-ice pt-5 sm:grid-cols-3">
                <x-form.input type="number" label="Days allowed" name="days_allowed" :value="$task?->days_allowed ?? 1" required min="0" max="3650" class="no-spinner"
                    hint="What the admin promised for this task. Not what a person is judged on — that is their stint's own allowance." />
                <x-form.input type="date" label="Starts" name="started_on" :value="$task?->started_on?->format('Y-m-d')" />
                <x-form.input type="date" label="Due" name="due_date" :value="$task?->due_date?->format('Y-m-d')" />
            </div>
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn><x-icon name="check" class="size-4" /> {{ $task ? 'Save changes' : 'Create task' }}</x-btn>
            <x-btn variant="ghost" :href="route('admin.tasks.index')">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
