@props(['padding' => true])

<div {{ $attributes->merge(['class' => 'rounded-2xl bg-white shadow-card '.($padding ? 'p-6' : '')]) }}>
    {{ $slot }}
</div>
