<x-admin.layout :title="$holiday ? 'Edit holiday' : 'New holiday'">
    <x-page-header
        eyebrow="System"
        :title="$holiday ? 'Edit '.$holiday->name : 'New holiday'"
        description="A holiday closes the academy for everybody, including students on a slot that runs that weekday."
        :crumbs="['Settings' => route('admin.settings.edit'), 'Holidays' => route('admin.holidays.index'), ($holiday ? 'Edit' : 'New') => null]">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.holidays.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to holidays
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ $holiday ? route('admin.holidays.update', $holiday) : route('admin.holidays.store') }}" class="max-w-2xl">
        @csrf
        @if ($holiday) @method('PUT') @endif

        <x-form.errors-summary />

        <x-card class="space-y-5">
            <x-form.input label="Holiday name" name="name" :value="$holiday?->name" required
                placeholder="e.g. Eid ul-Fitr" hint="Shown on the register and in the student portal for that day." />

            <div class="grid gap-5 border-t border-surface-ice pt-5 sm:grid-cols-2">
                <x-form.input type="date" label="Date" name="date"
                    :value="$holiday?->date?->toDateString()" required
                    :hint="$holiday ? 'Asia/Karachi.' : 'The first day. Asia/Karachi.'" />

                @unless ($holiday)
                    {{-- Eid is three days, so a range is the normal case. It is
                         expanded into one row per date on save: everything that
                         reads this table asks about a single date. --}}
                    <x-form.input type="date" label="Last day (optional)" name="to_date" :value="old('to_date')"
                        hint="Leave blank for a single day. A range becomes one row per date." />
                @endunless
            </div>
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn>
                <x-icon name="check" class="size-4" />
                {{ $holiday ? 'Save changes' : 'Add holiday' }}
            </x-btn>
            <x-btn variant="ghost" :href="route('admin.holidays.index')">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
