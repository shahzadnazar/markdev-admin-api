@props(['label'])

<div class="mt-7 first:mt-0">
    <p class="nav-section-label mb-2 px-3 font-mono text-[10px] font-medium uppercase tracking-[0.18em] text-outline">{{ $label }}</p>
    <nav class="space-y-0.5">
        {{ $slot }}
    </nav>
</div>
