<x-admin.layout title="Clients">
    <x-page-header eyebrow="Team" title="Clients"
        description="Everyone MarkDev does work for. Only this screen and the project forms know who a project is for — a team sees a project by its name and code."
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Clients' => null]">
        <x-slot:actions>
            @can('clients.create')
                <x-btn :href="route('admin.clients.create')">
                    <x-icon name="plus" class="size-4" /> New client
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-form.errors-summary />

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Client</th>
                <th class="th">Contact</th>
                <th class="th td-num">Projects</th>
                <th class="th">Status</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($clients as $client)
                <tr class="row">
                    <td class="td">
                        <a href="{{ route('admin.clients.show', $client) }}" class="font-medium text-on-surface hover:text-primary">{{ $client->name }}</a>
                        @if ($client->company)
                            <p class="mt-0.5 text-[11px] text-outline">{{ $client->company }}</p>
                        @endif
                    </td>
                    <td class="td">
                        <p class="text-sm text-on-surface-variant">{{ $client->email ?: '—' }}</p>
                        @if ($client->phone)
                            <p class="mt-0.5 font-mono text-[11px] text-outline">{{ $client->phone }}</p>
                        @endif
                    </td>
                    <td class="td td-num"><x-badge variant="primary">{{ $client->projects_count }}</x-badge></td>
                    <td class="td">
                        @if ($client->is_active)
                            <x-badge variant="success">Active</x-badge>
                        @else
                            <x-badge variant="neutral">Not taking work</x-badge>
                        @endif
                    </td>
                    <td class="td text-right">
                        <div class="flex items-center justify-end gap-1">
                            @can('clients.update')
                                <x-confirm-form :action="route('admin.clients.toggle', $client)" method="POST"
                                    :title="$client->is_active ? 'Deactivate client' : 'Reactivate client'"
                                    :message="$client->is_active
                                        ? 'Stop offering '.$client->name.' on new projects? Their existing projects are unchanged.'
                                        : 'Offer '.$client->name.' on new projects again?'"
                                    :confirm-label="$client->is_active ? 'Deactivate' : 'Reactivate'"
                                    variant="primary"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                    <x-icon :name="$client->is_active ? 'eye' : 'restore'" class="size-4" />
                                </x-confirm-form>
                                <a href="{{ route('admin.clients.edit', $client) }}" aria-label="Edit client" title="Edit client" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                    <x-icon name="pencil" class="size-4" />
                                </a>
                            @endcan
                            @can('clients.delete')
                                <x-confirm-form :action="route('admin.clients.destroy', $client)" method="DELETE"
                                    title="Delete client"
                                    :message="$client->projects_count > 0
                                        ? $client->name.' has '.$client->projects_count.' project(s). Deleting is refused while they do — deactivate instead.'
                                        : 'Delete '.$client->name.'?'"
                                    confirm-label="Delete"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                    <x-icon name="trash" class="size-4" />
                                </x-confirm-form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">
                        <x-empty-state icon="users" title="No clients yet"
                            description="Add the first client to start setting up projects. A project needs a client, a team and a status." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-table>
</x-admin.layout>
