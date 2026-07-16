@props(['label', 'value', 'sub' => null, 'icon' => null, 'tone' => 'primary'])

@php
$tones = [
    'primary' => 'bg-primary/10 text-primary',
    'secondary' => 'bg-secondary/10 text-secondary',
    'success' => 'bg-success-container text-success',
    'warning' => 'bg-warning-container text-warning',
    'danger' => 'bg-error-container text-error',
];
@endphp

<div {{ $attributes->merge(['class' => 'rounded-2xl bg-white p-6 shadow-card']) }}>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="font-mono text-[11px] font-medium uppercase tracking-[0.12em] text-on-surface-variant">{{ $label }}</p>
            <p class="mt-2 font-display text-3xl font-bold tracking-[-0.02em] text-on-surface">{{ $value }}</p>
            @if ($sub)
                <p class="mt-1 text-xs text-outline">{{ $sub }}</p>
            @endif
        </div>
        @if ($icon)
            <div class="flex size-11 shrink-0 items-center justify-center rounded-xl {{ $tones[$tone] ?? $tones['primary'] }}">
                <x-icon :name="$icon" class="size-5" />
            </div>
        @endif
    </div>
    {{ $slot }}
</div>
