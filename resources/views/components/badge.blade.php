@props(['variant' => 'neutral'])

@php
$variants = [
    'neutral' => 'bg-on-surface-variant/10 text-on-surface-variant',
    'primary' => 'bg-primary/10 text-primary',
    'secondary' => 'bg-secondary/10 text-secondary',
    'success' => 'bg-success-container text-success',
    'warning' => 'bg-warning-container text-warning',
    'danger' => 'bg-error-container text-error',
];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-mono text-[11px] font-medium uppercase tracking-[0.05em] '.($variants[$variant] ?? $variants['neutral'])]) }}>{{ $slot }}</span>
