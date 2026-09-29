<x-admin.layout :title="$client->name">
    <x-page-header eyebrow="Team" :title="$client->name"
        :description="$client->company ?: 'No company on file.'"
        :crumbs="['Clients' => route('admin.clients.index'), $client->name => null]">
        <x-slot:actions>
            @can('clients.update')
                <x-btn :href="route('admin.clients.edit', $client)">
                    <x-icon name="pencil" class="size-4" /> Edit client
                </x-btn>
            @endcan
            <x-btn variant="ghost" :href="route('admin.clients.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to clients
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-[320px_minmax(0,1fr)]">
        <x-card>
            <h2 class="font-display text-[15px] font-semibold text-on-surface">Contact</h2>
            <dl class="mt-4 space-y-3 text-[13px]">
                <div>
                    <dt class="text-outline">Email</dt>
                    <dd class="mt-0.5 text-on-surface">{{ $client->email ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-outline">Phone</dt>
                    <dd class="mt-0.5 font-mono text-on-surface">{{ $client->phone ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-outline">Address</dt>
                    <dd class="mt-0.5 whitespace-pre-line text-on-surface">{{ $client->address ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-outline">Login</dt>
                    <dd class="mt-0.5 text-on-surface">{{ $client->user?->email ?? 'None yet' }}</dd>
                </div>
            </dl>

            @if ($client->notes)
                <div class="mt-5 border-t border-surface-ice pt-4">
                    <h3 class="text-[13px] font-medium text-on-surface">Notes</h3>
                    <p class="mt-1 whitespace-pre-line text-[13px] leading-5 text-on-surface-variant">{{ $client->notes }}</p>
                </div>
            @endif
        </x-card>

        <x-card>
            <div class="flex items-start justify-between gap-4">
                <h2 class="font-display text-[15px] font-semibold text-on-surface">Projects</h2>
                @can('projects.create')
                    <x-btn variant="secondary" size="sm" :href="route('admin.projects.create')">
                        <x-icon name="plus" class="size-4" /> New project
                    </x-btn>
                @endcan
            </div>

            <div class="mt-4 space-y-2">
                @forelse ($projects as $project)
                    <a href="{{ route('admin.projects.show', $project) }}" class="flex items-center justify-between gap-3 rounded-xl border border-outline-variant/60 px-4 py-3 transition hover:border-primary/40">
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-on-surface">{{ $project->name }}</span>
                            <span class="mt-0.5 block font-mono text-[11px] text-outline">{{ $project->code }} · {{ $project->team?->name }}</span>
                        </span>
                        <span class="shrink-0 rounded-full px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-[0.08em]"
                            style="background-color: {{ $project->status?->colour }}1a; color: {{ $project->status?->colour }}">
                            {{ $project->status?->label }}
                        </span>
                    </a>
                @empty
                    <p class="text-[13px] text-on-surface-variant">No projects for this client yet.</p>
                @endforelse
            </div>
        </x-card>
    </div>
</x-admin.layout>
