{{-- A LIST screen, so it reads the cached rows rather than recomputing twelve
     times. Each row is drawn by the one score component, which refuses to
     render a percentage without its counts. --}}
<x-admin.layout :title="$team->name.' — delivery'">
    <x-page-header eyebrow="Team" :title="$team->name" description="Delivery across every stint these members have finished."
        :crumbs="['Teams' => route('admin.teams.index'), $team->name => null, 'Delivery' => null]">
        <x-slot:actions>
            <form method="POST" action="{{ route('admin.teams.scores.refresh', $team) }}">
                @csrf
                <x-btn variant="secondary" size="md">
                    <x-icon name="restore" class="size-4" /> Recompute
                </x-btn>
            </form>
            <x-btn variant="ghost" :href="route('admin.teams.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to teams
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($members as $member)
            <x-card>
                <x-team.delivery-score
                    :name="$member->name"
                    :compact="true"
                    :score="$scores->get($member->id)?->toScoreArray() ?? [
                        'percent' => null,
                        'stints_completed' => 0,
                        'late_count' => 0,
                        'days_over' => 0,
                        'blocked_days' => 0,
                        'minimum' => \App\Support\DeliveryScore::minimumStints(),
                    ]" />
            </x-card>
        @endforeach
    </div>
</x-admin.layout>
