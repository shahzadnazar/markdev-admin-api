<x-admin.layout title="Teams">
    <x-page-header eyebrow="Team" title="Teams"
        description="The groups MarkDev's client work is assigned to. Each team has one lead, who is also a member of it."
        :crumbs="['Dashboard' => \App\Support\PortalHome::url(), 'Teams' => null]">
        <x-slot:actions>
            @can('teams.create')
                <x-btn :href="route('admin.teams.create')">
                    <x-icon name="plus" class="size-4" /> New team
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-form.errors-summary />

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Team</th>
                <th class="th">Lead</th>
                <th class="th td-num">Members</th>
                <th class="th">Status</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($teams as $team)
                <tr class="row">
                    <td class="td">
                        <p class="font-medium text-on-surface">{{ $team->name }}</p>
                    </td>
                    <td class="td">
                        @if ($team->lead)
                            <span class="text-sm text-on-surface">{{ $team->lead->name }}</span>
                        @else
                            {{-- Only reachable if the lead's account was erased:
                                 the FK nulls rather than taking the team with it. --}}
                            <span class="text-sm text-outline">No lead</span>
                        @endif
                    </td>
                    <td class="td td-num"><x-badge variant="primary">{{ $team->members_count }}</x-badge></td>
                    <td class="td">
                        @if ($team->is_active)
                            <x-badge variant="success">Active</x-badge>
                        @else
                            <x-badge variant="neutral">Not taking work</x-badge>
                        @endif
                    </td>
                    <td class="td text-right">
                        <div class="flex items-center justify-end gap-1">
                            @can('teams.update')
                                <x-confirm-form :action="route('admin.teams.toggle', $team)" method="POST"
                                    :title="$team->is_active ? 'Stop new work' : 'Take work again'"
                                    :message="$team->is_active
                                        ? 'Stop pointing new work at '.$team->name.'? Its members, its projects and its history are all kept.'
                                        : 'Point new work at '.$team->name.' again?'"
                                    :confirm-label="$team->is_active ? 'Stop new work' : 'Take work again'"
                                    variant="primary"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                    <x-icon :name="$team->is_active ? 'eye' : 'restore'" class="size-4" />
                                </x-confirm-form>
                                <a href="{{ route('admin.teams.edit', $team) }}" aria-label="Edit team" title="Edit team" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                    <x-icon name="pencil" class="size-4" />
                                </a>
                            @endcan
                            @can('teams.delete')
                                <x-confirm-form :action="route('admin.teams.destroy', $team)" method="DELETE"
                                    title="Delete team"
                                    :message="'Delete '.$team->name.'? Its '.$team->members_count.' membership(s) and its history are kept, and the name becomes free again.'"
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
                        <x-empty-state icon="users" title="No teams yet"
                            description="Create the first team to start assigning client work. A team needs a lead and at least one member — the lead is one of the members." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-table>
</x-admin.layout>
