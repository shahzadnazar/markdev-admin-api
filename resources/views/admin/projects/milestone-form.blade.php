<x-admin.layout :title="$milestone ? 'Edit milestone' : 'New milestone'">
    <x-page-header
        eyebrow="Team"
        :title="$milestone ? 'Edit '.$milestone->name : 'New milestone'"
        :description="'On '.$project->name.' ('.$project->code.').'"
        :crumbs="['Projects' => route('admin.projects.index'), $project->code => route('admin.projects.show', $project), ($milestone ? 'Edit milestone' : 'New milestone') => null]">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.projects.show', $project)">
                <x-icon name="arrow-left" class="size-4" /> Back to project
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ $milestone ? route('admin.projects.milestones.update', [$project, $milestone]) : route('admin.projects.milestones.store', $project) }}" class="max-w-2xl">
        @csrf
        @if ($milestone) @method('PUT') @endif

        <x-form.errors-summary />

        <x-card class="space-y-5">
            <x-form.input label="Milestone" name="name" :value="$milestone?->name" required
                placeholder="e.g. Design sign-off" />

            <div class="grid gap-5 border-t border-surface-ice pt-5 sm:grid-cols-2">
                <x-form.input type="date" label="Due date" name="due_date" :value="$milestone?->due_date?->format('Y-m-d')" />
                {{-- A completion is a DAY, not a tick-box: "when" answers
                     "whether" and says when, which a boolean would lose. --}}
                <x-form.input type="date" label="Completed on" name="completed_on" :value="$milestone?->completed_on?->format('Y-m-d')"
                    hint="Leave blank while it is outstanding." />
            </div>

            <div class="border-t border-surface-ice pt-5">
                <x-form.toggle label="Share with the client" name="is_client_visible"
                    :checked="$milestone?->is_client_visible ?? false"
                    hint="Nothing shows this yet — the client portal is a later phase. Recorded now so a milestone marked today means the same thing then." />
            </div>
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn>
                <x-icon name="check" class="size-4" />
                {{ $milestone ? 'Save changes' : 'Add milestone' }}
            </x-btn>
            <x-btn variant="ghost" :href="route('admin.projects.show', $project)">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
