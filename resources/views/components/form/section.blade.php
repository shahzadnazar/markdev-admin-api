@props(['title', 'description' => null])

{{-- Standard form section: one heading style for every big form. --}}
<div {{ $attributes->merge(['class' => 'border-t border-surface-ice pt-5 first:border-t-0 first:pt-0']) }}>
    <div class="mb-4">
        <h2 class="font-display text-[15px] font-semibold text-on-surface">{{ $title }}</h2>
        @if ($description)
            <p class="mt-0.5 text-[13px] leading-5 text-on-surface-variant">{{ $description }}</p>
        @endif
    </div>
    <div class="space-y-4">{{ $slot }}</div>
</div>
