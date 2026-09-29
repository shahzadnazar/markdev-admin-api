<x-admin.layout title="Assign task">
    <x-page-header eyebrow="Team" :title="$task->openAssignment ? 'Hand over '.$task->title : 'Assign '.$task->title"
        description="A stint is one person's promise for a stated number of days. It is the only thing their score is measured against."
        :crumbs="['Tasks' => route('admin.tasks.index'), $task->title => route('admin.tasks.show', $task), 'Assign' => null]">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.tasks.show', $task)">
                <x-icon name="arrow-left" class="size-4" /> Back to task
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('admin.tasks.assign.store', $task) }}" class="max-w-xl">
        @csrf
        <x-form.errors-summary />

        <x-card class="space-y-5">
            @if ($task->openAssignment)
                <div class="rounded-xl border border-warning/30 bg-warning-container/40 px-4 py-3">
                    <p class="text-sm font-semibold text-on-surface">{{ $task->openAssignment->user?->name }} currently holds this task.</p>
                    <p class="mt-1 text-[13px] leading-5 text-on-surface-variant">
                        Handing it over closes their stint as <strong>handed over</strong> — neither on time nor late, and in
                        neither half of their score. Their days stay visible on the task.
                        <strong>Their remaining days are not carried over.</strong> Type the days the new person is being given;
                        a stint opened with whatever was left would blame them for a delay that was not theirs.
                    </p>
                </div>
            @endif

            <x-form.select label="Assign to" name="user_id" required hint="Somebody on this task's team.">
                <option value="">Pick a member</option>
                @foreach ($members as $member)
                    <option value="{{ $member->id }}" @selected((string) old('user_id') === (string) $member->id)>{{ $member->name }}</option>
                @endforeach
            </x-form.select>

            <x-form.input type="number" label="Days allowed for this stint" name="days_allowed" :value="old('days_allowed', 1)" required min="1" max="3650" class="no-spinner"
                hint="What this person is promising, and the only number their score is measured against. Never the parent task's." />
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn><x-icon name="check" class="size-4" /> {{ $task->openAssignment ? 'Hand over' : 'Assign' }}</x-btn>
            <x-btn variant="ghost" :href="route('admin.tasks.show', $task)">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
