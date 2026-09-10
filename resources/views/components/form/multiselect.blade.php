@props([
    'label' => null,
    'name',
    'options' => [],
    'selected' => [],
    'placeholder' => 'Any',
    'allLabel' => 'Select all',
    'width' => 'w-44',
    // The students screen narrows as you tick, through its own [data-live]
    // handler. Off elsewhere, where the Filter button submits the form.
    'live' => false,
])

@php
    // Everything is compared as a string. The values arrive as ints for an id
    // filter and strings for a status one, and `['3'].includes(3)` is false —
    // a mismatch that shows as a filter which silently forgets what was ticked.
    $all = collect($options)->keys()->map(fn ($value) => (string) $value)->values()->all();
    $current = collect($selected)->map(fn ($value) => (string) $value)->values()->intersect($all)->values()->all();
@endphp

{{--
    Native checkboxes on purpose, the same reason x-form.days gives: the chips
    this pattern replaced were styled with `peer-checked:` variants that
    Tailwind only emits when it sees them at build time, and `public/build` is
    gitignored — so anyone pulling the Blade without re-running `npm run build`
    got chips that toggled invisibly and read as dead. A real checkbox needs no
    generated class to look checked.

    Kept separate from x-form.days rather than made its parent. That control's
    whole value is a summary this one cannot produce — it collapses a run of
    days into "Mon–Fri" — over a fixed, integer-keyed set. Parameterising this
    far enough to reach it would be harder to read than two files that share a
    shape. What they must not diverge on is the checkbox decision above, which
    is why the reason is written out in both.
--}}
<div class="{{ $width }}" x-data="{
        open: false,
        picked: @js($current),
        all: @js($all),
        get every() { return this.all.length > 0 && this.picked.length === this.all.length },
        get some() { return this.picked.length > 0 && ! this.every },
        toggleEvery(on) { this.picked = on ? [...this.all] : [] },
        get summary() {
            if (this.picked.length === 0) return @js($placeholder);
            if (this.every) return 'All ' + this.all.length + ' selected';
            return this.picked.length + ' of ' + this.all.length + ' selected';
        },
    }">
    @if ($label)
        <x-form.label :for="$name" :value="$label" />
    @endif

    <div class="relative">
        <button type="button" id="{{ $name }}"
            class="field flex w-full items-center justify-between gap-2 text-left"
            x-on:click="open = ! open"
            x-on:keydown.escape="open = false"
            :aria-expanded="open.toString()"
            aria-haspopup="true">
            <span class="truncate" x-text="summary"
                :class="picked.length === 0 ? 'text-on-surface-variant' : 'text-on-surface'">{{ $current === [] ? $placeholder : count($current).' of '.count($all).' selected' }}</span>
            <svg class="size-4 shrink-0 text-outline transition-transform" :class="open ? 'rotate-180' : ''"
                fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
        </button>

        <div x-show="open" x-cloak x-transition.opacity.duration.100ms
            x-on:click.outside="open = false"
            x-on:keydown.escape="open = false; $refs.trigger?.focus()"
            class="absolute z-30 mt-1 max-h-72 w-full min-w-max overflow-y-auto rounded-xl border border-surface-ice bg-white p-2 shadow-elevated">

            @if ($all !== [])
                <label class="flex cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-on-surface hover:bg-surface-ice">
                    {{-- `indeterminate` is a DOM property, not an attribute, so
                         no amount of markup sets it — x-effect writes it every
                         time the tick count changes, which is what makes the
                         half-state real for a screen reader as well as the eye. --}}
                    <input type="checkbox" class="size-4 cursor-pointer rounded"
                        :checked="every"
                        x-effect="$el.indeterminate = some"
                        :aria-checked="some ? 'mixed' : every.toString()"
                        @if ($live) data-live @endif
                        x-on:change="toggleEvery($event.target.checked)">
                    <span class="font-medium">{{ $allLabel }}</span>
                </label>

                <div class="mt-1 mb-1 border-t border-surface-ice"></div>
            @endif

            @forelse ($options as $value => $optionLabel)
                <label class="flex cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-on-surface hover:bg-surface-ice">
                    <input type="checkbox" name="{{ $name }}[]" value="{{ $value }}"
                        class="size-4 cursor-pointer rounded" @if ($live) data-live @endif x-model="picked">
                    <span class="whitespace-nowrap">{{ $optionLabel }}</span>
                </label>
            @empty
                <p class="px-3 py-2 text-sm text-outline">Nothing to filter by.</p>
            @endforelse
        </div>
    </div>
</div>
