@props([])

<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-2xl bg-white shadow-card']) }}>
    <div class="scroll-thin overflow-x-auto">
        <table class="w-full min-w-[640px] text-left">
            {{ $slot }}
        </table>
    </div>
    @isset($footer)
        <div class="border-t border-surface-ice px-4 py-3">{{ $footer }}</div>
    @endisset
</div>
