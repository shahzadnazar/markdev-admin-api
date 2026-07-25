@props(['label' => null, 'name', 'type' => 'text', 'value' => null, 'hint' => null, 'required' => false])

<div class="min-w-0">
    @if ($label)
        <x-form.label :for="$name" :value="$label" :required="$required" />
    @endif
    <input
        type="{{ $type }}"
        name="{{ $name }}"
        id="{{ $name }}"
        @if ($type !== 'password' && $type !== 'file') value="{{ old($name, $value) }}" @endif
        @if ($required) required @endif
        {{ $attributes->merge(['class' => 'field'.($errors->has($name) ? ' border-error focus:border-error focus:ring-error/15' : '')]) }}
    >
    @if ($hint)
        <p class="mt-1.5 text-xs text-outline">{{ $hint }}</p>
    @endif
    <x-form.error :name="$name" />
</div>
