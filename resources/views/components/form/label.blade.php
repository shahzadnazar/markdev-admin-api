@props(['for' => null, 'value' => null])

<label @if($for) for="{{ $for }}" @endif {{ $attributes->merge(['class' => 'mb-1.5 block text-[13px] font-medium text-on-surface']) }}>
    {{ $value ?? $slot }}
</label>
