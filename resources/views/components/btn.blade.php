@props(['variant' => 'primary', 'href' => null, 'type' => 'submit', 'size' => 'md'])

@php
$base = 'cursor-pointer inline-flex items-center justify-center gap-2 rounded-lg font-medium transition duration-150 focus:outline-none focus-visible:ring-4 focus-visible:ring-primary/25 disabled:opacity-50 disabled:pointer-events-none';

$sizes = [
    'sm' => 'px-3 py-1.5 text-xs',
    'md' => 'px-4 py-2.5 text-sm',
    'lg' => 'px-5 py-3 text-sm',
];

$variants = [
    'primary' => 'bg-primary text-white shadow-card hover:bg-primary-deep hover:-translate-y-px active:translate-y-0',
    'secondary' => 'border border-primary/40 bg-white text-primary hover:bg-primary/5 hover:border-primary',
    'ghost' => 'text-on-surface-variant hover:bg-primary/5 hover:text-primary',
    'success' => 'bg-success text-white shadow-card hover:bg-success/90 hover:-translate-y-px active:translate-y-0',
    'danger' => 'bg-error text-white hover:bg-error/90 hover:-translate-y-px active:translate-y-0',
    'danger-ghost' => 'text-error hover:bg-error/10',
    'warning' => 'bg-warning text-white shadow-card hover:bg-warning/90 hover:-translate-y-px active:translate-y-0',
];

$classes = $base.' '.$sizes[$size].' '.$variants[$variant];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}"
        @if ($type === 'submit')
            {{-- Submit-once guard: disable after the click has dispatched the
                 native submit, so double-clicks can't double-post. --}}
            onclick="const f=this.closest('form'); if(f && f.checkValidity()) setTimeout(() => { this.disabled = true; this.classList.add('opacity-60'); }, 0)"
        @endif
        {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
