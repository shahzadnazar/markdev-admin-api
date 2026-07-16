@props(['label' => null, 'name', 'value' => null, 'rows' => 5, 'hint' => null, 'required' => false])

<div class="min-w-0">
    @if ($label)
        <x-form.label :for="$name" :value="$label" />
    @endif
    <textarea
        name="{{ $name }}"
        id="{{ $name }}"
        rows="{{ $rows }}"
        @if ($required) required @endif
        {{ $attributes->merge(['class' => 'field'.($errors->has($name) ? ' border-error focus:border-error focus:ring-error/15' : '')]) }}
    >{{ old($name, $value) }}</textarea>
    @if ($hint)
        <p class="mt-1.5 text-xs text-outline">{{ $hint }}</p>
    @endif
    <x-form.error :name="$name" />
</div>
