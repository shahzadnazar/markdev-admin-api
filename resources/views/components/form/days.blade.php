@props(['label' => 'Runs on', 'name' => 'days', 'selected' => null, 'hint' => null, 'required' => false])

@php
    use App\Models\AttendanceSlot;

    // A new slot starts on the whole week, which is what every slot did before
    // days existed and is the answer most timetables want.
    $current = collect(old($name, $selected ?? array_keys(AttendanceSlot::DAYS)))
        ->map(fn ($day) => (int) $day)
        ->all();
    $invalid = $errors->has($name);
@endphp

<div x-data="{
        days: @js($current),
        all: @js(array_keys(AttendanceSlot::DAYS)),
        get every() { return this.all.every(day => this.days.includes(day)) },
        toggleEvery(on) { this.days = on ? [...this.all] : [] },
    }">
    @if ($label)
        <x-form.label :value="$label" :required="$required" />
    @endif

    <div class="flex flex-wrap gap-2">
        @foreach (AttendanceSlot::DAYS as $number => $day)
            <label class="cursor-pointer">
                <input type="checkbox" name="{{ $name }}[]" value="{{ $number }}" class="peer sr-only"
                    x-model.number="days">
                <span class="inline-flex items-center rounded-lg border border-surface-ice px-3 py-2 text-sm text-on-surface-variant transition hover:border-primary/40 peer-checked:border-primary peer-checked:bg-primary/10 peer-checked:font-medium peer-checked:text-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary/30">
                    {{ mb_substr($day, 0, 3) }}
                </span>
            </label>
        @endforeach

        <label class="cursor-pointer">
            {{-- Not a day of its own: it ticks and unticks the seven above. --}}
            <input type="checkbox" class="peer sr-only"
                :checked="every" x-on:change="toggleEvery($event.target.checked)">
            <span class="inline-flex items-center rounded-lg border border-dashed border-outline/40 px-3 py-2 text-sm text-on-surface-variant transition hover:border-primary/40 peer-checked:border-primary peer-checked:bg-primary/10 peer-checked:font-medium peer-checked:text-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary/30">
                Every day
            </span>
        </label>
    </div>

    <p class="mt-1.5 text-xs text-outline">
        <span x-show="days.length === 0" class="text-error">Pick at least one day.</span>
        <span x-show="days.length > 0">{{ $hint ?? 'The slot only judges lateness on the days it runs.' }}</span>
    </p>

    <x-form.error :name="$name" />
</div>
