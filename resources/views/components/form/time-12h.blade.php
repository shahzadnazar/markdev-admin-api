@props(['label' => null, 'name', 'value' => null, 'hint' => null, 'required' => false])

@php
    // Stored 24-hour, entered 12-hour. The three parts post as
    // {name}_hour / {name}_minute / {name}_meridiem and the controller
    // recombines them; errors are reported against the base name.
    $stored = old($name.'_hour') !== null
        ? null
        : ($value ? \Illuminate\Support\Carbon::parse($value) : null);

    $hour = old($name.'_hour', $stored?->format('g') ?? '9');
    $minute = old($name.'_minute', $stored?->format('i') ?? '00');
    $meridiem = old($name.'_meridiem', $stored?->format('A') ?? 'AM');
    $invalid = $errors->has($name);
    $fieldClass = 'field'.($invalid ? ' border-error focus:border-error focus:ring-error/15' : '');
@endphp

<div class="min-w-0">
    @if ($label)
        <x-form.label :for="$name.'_hour'" :value="$label" :required="$required" />
    @endif
    <div class="flex items-center gap-1.5">
        <select name="{{ $name }}_hour" id="{{ $name }}_hour" class="{{ $fieldClass }} w-[4.5rem]" aria-label="{{ $label }} hour">
            @foreach (range(1, 12) as $option)
                <option value="{{ $option }}" @selected((int) $hour === $option)>{{ $option }}</option>
            @endforeach
        </select>
        <span class="font-display text-lg text-outline">:</span>
        <select name="{{ $name }}_minute" id="{{ $name }}_minute" class="{{ $fieldClass }} w-[4.5rem]" aria-label="{{ $label }} minute">
            @foreach (range(0, 59) as $option)
                <option value="{{ $option }}" @selected((int) $minute === $option)>{{ str_pad((string) $option, 2, '0', STR_PAD_LEFT) }}</option>
            @endforeach
        </select>
        <select name="{{ $name }}_meridiem" id="{{ $name }}_meridiem" class="{{ $fieldClass }} w-[5rem]" aria-label="{{ $label }} AM or PM">
            @foreach (['AM', 'PM'] as $option)
                <option value="{{ $option }}" @selected($meridiem === $option)>{{ $option }}</option>
            @endforeach
        </select>
    </div>
    @if ($hint)
        <p class="mt-1.5 text-xs text-outline">{{ $hint }}</p>
    @endif
    <x-form.error :name="$name" />
</div>
