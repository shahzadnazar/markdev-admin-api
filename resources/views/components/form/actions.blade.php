@props(['cancel' => null, 'cancelLabel' => 'Cancel'])

{{-- Sticky bottom action bar for long forms — Cancel stays neutral, the
     primary action arrives last via the slot. --}}
<div {{ $attributes->merge(['class' => 'sticky bottom-0 z-20 mt-6 rounded-t-xl border-t border-surface-ice bg-white/95 px-4 py-3 shadow-[0_-4px_16px_rgba(18,67,137,0.06)] backdrop-blur']) }}>
    <div class="flex items-center justify-end gap-2.5">
        @if ($cancel)
            <x-btn variant="ghost" :href="$cancel">{{ $cancelLabel }}</x-btn>
        @endif
        {{ $slot }}
    </div>
</div>
