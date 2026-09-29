{{-- THE SHARED SCREEN.

     A team lead or team member reaches this page, narrowed by
     Project::visibleTo to the teams they are a member of. What they must never
     see is WHO the project is for and WHAT it is worth, so both live inside
     @can('clients.view') — chosen deliberately: the thing being protected is
     the client and what they are paying, and anyone entitled to open the client
     list already knows both. Project::FULL_VIEW_PERMISSION is the same string,
     named in PHP so the query scope and this markup cannot part company.

     The controller does not even load the client for a viewer who fails that
     gate, so there is nothing here to render by accident. --}}
<x-admin.layout title="Projects">
    <x-page-header eyebrow="Team" title="Projects"
        description="Client work, one team per project. A project is identified by its name and its code."
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Projects' => null]">
        <x-slot:actions>
            @can('projects.create')
                <x-btn :href="route('admin.projects.create')">
                    <x-icon name="plus" class="size-4" /> New project
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-form.errors-summary />

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
        @can('clients.view')
            <x-form.select label="Client" name="client" class="w-56">
                <option value="">Any client</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}" @selected((string) request('client') === (string) $client->id)>{{ $client->company ?: $client->name }}</option>
                @endforeach
            </x-form.select>
        @endcan
        <x-btn variant="secondary" size="md" type="submit">
            <x-icon name="funnel" class="size-4" /> Filter
        </x-btn>
        <x-btn variant="ghost" size="md" :href="route('admin.projects.index')">Clear</x-btn>
    </form>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Project</th>
                @can('clients.view')<th class="th">Client</th>@endcan
                <th class="th">Team</th>
                <th class="th">Status</th>
                <th class="th">Due</th>
                @can('clients.view')<th class="th td-num">Value</th>@endcan
            </tr>
        </thead>
        <tbody>
            @forelse ($projects as $project)
                <tr class="row">
                    <td class="td">
                        <a href="{{ route('admin.projects.show', $project) }}" class="font-medium text-on-surface hover:text-primary">{{ $project->name }}</a>
                        <p class="mt-0.5 font-mono text-[11px] text-outline">{{ $project->code }}</p>
                    </td>
                    @can('clients.view')
                        <td class="td">
                            <span class="text-sm text-on-surface-variant">{{ $project->client?->company ?: $project->client?->name }}</span>
                        </td>
                    @endcan
                    <td class="td"><span class="text-sm text-on-surface-variant">{{ $project->team?->name }}</span></td>
                    <td class="td">
                        <span class="inline-flex items-center gap-2">
                            <span class="size-2.5 shrink-0 rounded-full ring-1 ring-inset ring-black/10" style="background-color: {{ $project->status?->colour }}"></span>
                            <span class="text-sm text-on-surface">{{ $project->status?->label }}</span>
                        </span>
                    </td>
                    <td class="td">
                        <x-projects.days :project="$project" />
                    </td>
                    @can('clients.view')
                        <td class="td td-num">
                            @if ($project->contract_value !== null)
                                <span class="font-mono text-sm text-on-surface">{{ $project->currency }} {{ number_format((float) $project->contract_value, 0) }}</span>
                            @else
                                <span class="text-sm text-outline">—</span>
                            @endif
                        </td>
                    @endcan
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-empty-state icon="clipboard" title="No projects"
                            description="Nothing matches this filter. Projects you can see are the ones belonging to a team you are on." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-table>
</x-admin.layout>
