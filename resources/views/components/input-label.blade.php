@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-[13px] font-medium text-on-surface']) }}>
    {{ $value ?? $slot }}
</label>
