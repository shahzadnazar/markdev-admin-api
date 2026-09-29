<x-admin.layout :title="$team ? 'Edit team' : 'New team'">
    <x-page-header
        eyebrow="Team"
        :title="$team ? 'Edit '.$team->name : 'New team'"
        description="One lead, who is also a member. Somebody may be on several teams — a designer working across two products is on both."
        :crumbs="['Teams' => route('admin.teams.index'), ($team ? 'Edit' : 'New') => null]">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.teams.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to teams
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    @php
        // Everything Alpine compares is a string: a checkbox value is a string
        // and a <select> value is a string, and `['3'].includes(3)` is false —
        // the same mismatch the multi-select filters ran into.
        $chosenMembers = collect(old('members', $team?->members->pluck('id')->all() ?? []))
            ->map(fn ($id) => (string) $id)->values()->all();
        $chosenLead = (string) old('team_lead_id', $team?->team_lead_id ?? '');
    @endphp

    <form method="POST" action="{{ $team ? route('admin.teams.update', $team) : route('admin.teams.store') }}" class="max-w-2xl"
        x-data="{ members: @js($chosenMembers), lead: @js($chosenLead) }">
        @csrf
        @if ($team) @method('PUT') @endif

        <x-form.errors-summary />

        <x-card class="space-y-5">
            <x-form.input label="Team name" name="name" :value="$team?->name" required
                placeholder="e.g. Web" hint="Whatever MarkDev calls this team. One team, one name — and a deleted team's name is free again." />

            <div class="border-t border-surface-ice pt-5">
                <x-form.label value="Members" required />
                <div class="grid max-h-80 gap-2 overflow-y-auto pr-1 scroll-thin sm:grid-cols-2">
                    @foreach ($staff as $person)
                        <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-outline-variant/60 px-4 py-3 transition hover:border-primary/40">
                            <input type="checkbox" name="members[]" value="{{ $person->id }}" class="check" x-model="members">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-on-surface">{{ $person->name }}</span>
                                <span class="block truncate text-xs text-outline">{{ $person->email }}@unless ($person->is_active) · inactive @endunless</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <p class="mt-1.5 text-xs text-outline">Staff only. Deactivating someone's account leaves them on the team — the work they did still belongs to it.</p>
                <x-form.error name="members" />
                <x-form.error name="members.*" />
            </div>

            <div class="border-t border-surface-ice pt-5">
                <x-form.select label="Team lead" name="team_lead_id" required x-model="lead"
                    hint="Accountable for this team's work. They have to be ticked above as well — a lead who is not on the team is a manager of it, which is a different thing.">
                    <option value="">Pick the lead</option>
                    @foreach ($staff as $person)
                        <option value="{{ $person->id }}" @selected($chosenLead === (string) $person->id)>{{ $person->name }}</option>
                    @endforeach
                </x-form.select>
                {{-- Says so before the save rather than after it. The server
                     refuses it either way; this only saves a round trip. --}}
                <p x-cloak x-show="lead !== '' && ! members.includes(lead)" class="mt-1.5 text-xs font-medium text-warning">
                    Tick this person as a member too — the lead is one of the team.
                </p>
            </div>

            <div class="border-t border-surface-ice pt-5">
                <x-form.toggle label="Taking new work" name="is_active"
                    :checked="$team?->is_active ?? true"
                    hint="Turning this off stops new work being pointed at the team. Its members, its projects and its history are unchanged." />
            </div>
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn>
                <x-icon name="check" class="size-4" />
                {{ $team ? 'Save changes' : 'Create team' }}
            </x-btn>
            <x-btn variant="ghost" :href="route('admin.teams.index')">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
