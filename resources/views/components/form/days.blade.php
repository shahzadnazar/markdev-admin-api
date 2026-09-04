@props(['label' => 'Runs on', 'name' => 'days', 'selected' => null, 'hint' => null, 'required' => false])

@php
    use App\Models\AttendanceSlot;

    // A new slot starts on the whole week, which is what every slot did before
    // days existed and is the answer most timetables want.
    $current = collect(old($name, $selected ?? array_keys(AttendanceSlot::DAYS)))
        ->map(fn ($day) => (int) $day)
        ->values()
        ->all();
    $short = collect(AttendanceSlot::DAYS)->map(fn ($day) => mb_substr($day, 0, 3))->all();
@endphp

{{--
    Native checkboxes on purpose. The chips this replaced were styled with
    `peer-checked:` variants that Tailwind only emits when it sees them at build
    time, and `public/build` is gitignored — so anyone pulling the Blade without
    re-running `npm run build` got chips that toggled invisibly and read as dead.
    A real checkbox needs no generated class to look checked.
--}}
<div x-data="{
        open: false,
        days: @js($current),
        all: @js(array_keys(AttendanceSlot::DAYS)),
        short: @js($short),
        get every() { return this.all.every(day => this.days.includes(day)) },
        toggleEvery(on) { this.days = on ? [...this.all] : [] },
        get summary() {
            const picked = this.all.filter(day => this.days.includes(day));
            if (picked.length === 0) return 'No days picked';
            if (picked.length === this.all.length) return 'Every day';

            // Same shape as the server's label: a run of three or more reads
            // as a range, so 'Mon–Fri' rather than five names in a row.
            const parts = [];
            let run = [];
            const flush = () => {
                if (run.length === 0) return;
                parts.push(run.length >= 3
                    ? this.short[run[0]] + '–' + this.short[run[run.length - 1]]
                    : run.map(day => this.short[day]).join(', '));
                run = [];
            };
            for (const day of picked) {
                if (run.length && day !== run[run.length - 1] + 1) flush();
                run.push(day);
            }
            flush();
            return parts.join(', ');
        },
    }" class="relative">
    @if ($label)
        <x-form.label :value="$label" :required="$required" />
    @endif

    <button type="button" class="field flex w-full items-center justify-between gap-2 text-left"
        x-on:click="open = ! open" :aria-expanded="open.toString()" aria-haspopup="true">
        <span x-text="summary" :class="days.length === 0 ? 'text-error' : 'text-on-surface'">{{ AttendanceSlot::labelForDays($current) }}</span>
        <svg class="size-4 shrink-0 text-outline transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
        </svg>
    </button>

    <div x-show="open" x-cloak x-transition.opacity.duration.100ms x-on:click.outside="open = false"
        class="absolute z-20 mt-1 w-full rounded-xl border border-surface-ice bg-white p-2 shadow-elevated">
        <label class="flex cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-on-surface hover:bg-surface-ice">
            {{-- Not a day of its own: it ticks and unticks the seven below. --}}
            <input type="checkbox" class="size-4 cursor-pointer rounded"
                :checked="every" x-on:change="toggleEvery($event.target.checked)">
            <span class="font-medium">Every day</span>
        </label>

        <div class="mt-1 mb-1 border-t border-surface-ice"></div>

        @foreach (AttendanceSlot::DAYS as $number => $day)
            <label class="flex cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-on-surface hover:bg-surface-ice">
                <input type="checkbox" name="{{ $name }}[]" value="{{ $number }}"
                    class="size-4 cursor-pointer rounded" x-model.number="days">
                <span>{{ $day }}</span>
            </label>
        @endforeach
    </div>

    <p class="mt-1.5 text-xs text-outline">{{ $hint ?? 'The slot only judges lateness on the days it runs.' }}</p>

    <x-form.error :name="$name" />
</div>
