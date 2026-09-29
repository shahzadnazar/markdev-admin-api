{{-- ADMIN ONLY. The route asks for projects.create/update AND clients.view:
     a project must have a client, so there is no version of this form that
     both works and hides who the work is for. A team person never opens it. --}}
<x-admin.layout :title="$project ? 'Edit project' : 'New project'">
    <x-page-header
        eyebrow="Team"
        :title="$project ? 'Edit '.$project->name : 'New project'"
        description="One team per project. A client who needs two disciplines gets two projects — a shared project would mean a shared task list."
        :crumbs="['Projects' => route('admin.projects.index'), ($project ? $project->code : 'New') => null]">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.projects.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to projects
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ $project ? route('admin.projects.update', $project) : route('admin.projects.store') }}" class="max-w-2xl">
        @csrf
        @if ($project) @method('PUT') @endif

        <x-form.errors-summary />

        <x-card class="space-y-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.input label="Project name" name="name" :value="$project?->name" required
                    placeholder="e.g. Website Redesign"
                    hint="Two projects may share a name — the same job for two clients." />
                <x-form.input label="Code" name="code" :value="$project?->code" required
                    placeholder="e.g. PRJ-014"
                    hint="The unique handle. Case and spaces are ignored, and a deleted project's code is free again." />
            </div>

            <div class="grid gap-5 border-t border-surface-ice pt-5 sm:grid-cols-2">
                <x-form.select label="Client" name="client_id" required
                    hint="Never shown to the team doing the work.">
                    <option value="">Pick a client</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((string) old('client_id', $project?->client_id) === (string) $client->id)>{{ $client->company ? $client->company.' — '.$client->name : $client->name }}</option>
                    @endforeach
                </x-form.select>

                <x-form.select label="Team" name="team_id" required
                    hint="Exactly one. The team's members are who can see this project.">
                    <option value="">Pick a team</option>
                    @foreach ($teams as $team)
                        <option value="{{ $team->id }}" @selected((string) old('team_id', $project?->team_id) === (string) $team->id)>{{ $team->name }}</option>
                    @endforeach
                </x-form.select>
            </div>

            <div class="border-t border-surface-ice pt-5">
                <x-form.select label="Status" name="project_status_id" required
                    hint="Only statuses currently on offer. A retired one stays on the projects that already have it and is never given to a new one.">
                    <option value="">Pick a status</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->id }}" @selected((string) old('project_status_id', $project?->project_status_id) === (string) $status->id)>{{ $status->label }} · {{ $status->behaviourLabel() }}</option>
                    @endforeach
                </x-form.select>
            </div>

            <div class="grid gap-5 border-t border-surface-ice pt-5 sm:grid-cols-2">
                <x-form.input type="date" label="Start date" name="start_date" :value="$project?->start_date?->format('Y-m-d')" />
                <x-form.input type="date" label="Due date" name="due_date" :value="$project?->due_date?->format('Y-m-d')"
                    hint="A paused project stops counting down; a closed one has no countdown at all." />
            </div>

            <div class="grid gap-5 border-t border-surface-ice pt-5 sm:grid-cols-[minmax(0,1fr)_120px]">
                <x-form.input type="number" step="0.01" label="Contract value" name="contract_value"
                    :value="$project?->contract_value" min="0" class="no-spinner"
                    hint="The one money figure. There is no separate internal budget on purpose — two numbers for what a project is worth means two answers." />
                <x-form.input label="Currency" name="currency" :value="$project?->currency ?? 'PKR'" required
                    maxlength="3" placeholder="PKR" />
            </div>

            <div class="border-t border-surface-ice pt-5">
                <x-form.textarea label="Description" name="description" rows="4" :value="$project?->description" />
            </div>
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn>
                <x-icon name="check" class="size-4" />
                {{ $project ? 'Save changes' : 'Create project' }}
            </x-btn>
            <x-btn variant="ghost" :href="route('admin.projects.index')">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
