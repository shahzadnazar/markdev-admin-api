<x-admin.layout :title="$slot ? 'Edit slot' : 'New slot'">
    <x-page-header
        eyebrow="System"
        :title="$slot ? 'Edit '.$slot->name : 'New attendance slot'"
        description="A slot is a time of day that repeats every day. It holds no date — the date always comes from the punch being judged."
        :crumbs="['Settings' => route('admin.settings.edit'), 'Attendance slots' => route('admin.attendance-slots.index'), ($slot ? 'Edit' : 'New') => null]">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.attendance-slots.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to slots
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ $slot ? route('admin.attendance-slots.update', $slot) : route('admin.attendance-slots.store') }}" class="max-w-2xl">
        @csrf
        @if ($slot) @method('PUT') @endif

        <x-form.errors-summary />

        <x-card class="space-y-5">
            <x-form.input label="Slot name" name="name" :value="$slot?->name" required
                placeholder="e.g. Morning" hint="Whatever the academy calls this part of the day." />

            <div class="border-t border-surface-ice pt-5">
                <x-form.days label="Runs on" name="days" :selected="$slot?->dayNumbers()" required
                    hint="The slot only judges lateness on the days it runs. On any other day its students fall back to the academy day start." />
            </div>

            <div class="grid gap-5 border-t border-surface-ice pt-5 sm:grid-cols-2">
                <x-form.time-12h label="Starts at" name="start_time" :value="$slot?->start_time" required />
                <x-form.time-12h label="Ends at" name="end_time" :value="$slot?->end_time" required
                    hint="Must be later the same day — slots never run past midnight." />
            </div>

            <x-form.input type="number" label="Late after (minutes)" name="late_after_minutes"
                :value="$slot?->late_after_minutes ?? 15" required min="0" max="240" class="no-spinner"
                hint="Grace period from this slot's start. A student on this slot who punches in after it is marked late." />

            <div class="border-t border-surface-ice pt-5">
                <x-form.toggle label="Offer this slot on the registration form" name="is_active"
                    :checked="$slot?->is_active ?? true"
                    hint="Turning this off hides the slot from new admissions. Students already on it keep it and keep their timings." />
            </div>
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn>
                <x-icon name="check" class="size-4" />
                {{ $slot ? 'Save changes' : 'Create slot' }}
            </x-btn>
            <x-btn variant="ghost" :href="route('admin.attendance-slots.index')">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
