@props(['eyebrow' => null, 'title', 'description' => null])

<div {{ $attributes->merge(['class' => 'mb-8 flex flex-wrap items-end justify-between gap-4']) }}>
    <div class="min-w-0">
        @if ($eyebrow)
            <p class="eyebrow mb-2">{{ $eyebrow }}</p>
        @endif
        <h1 class="font-display text-[28px] font-bold leading-9 tracking-[-0.02em] text-on-surface">{{ $title }}</h1>
        @if ($description)
            <p class="mt-1.5 max-w-2xl text-sm leading-6 text-on-surface-variant">{{ $description }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex shrink-0 items-center gap-3">{{ $actions }}</div>
    @endisset
</div>
