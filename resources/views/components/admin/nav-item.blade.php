@props(['href', 'icon', 'active' => false])

@php $tooltip = trim(strip_tags($slot)); @endphp
<a href="{{ $href }}" title="{{ $tooltip }}"
    {{ $attributes->merge(['class' => 'nav-link group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors duration-150 '.($active
        ? 'bg-primary/8 font-semibold text-primary'
        : 'font-medium text-on-surface-variant hover:bg-surface-ice hover:text-on-surface')]) }}>
    {{-- 4px active bar on the sidebar's left edge --}}
    <span class="absolute -left-4 top-1/2 h-7 w-1 -translate-y-1/2 rounded-r-full bg-primary transition-opacity {{ $active ? 'opacity-100' : 'opacity-0' }}"></span>
    <x-icon :name="$icon" class="size-5 shrink-0 {{ $active ? 'text-primary' : 'text-outline group-hover:text-on-surface-variant' }}" />
    <span class="nav-label truncate">{{ $slot }}</span>
    @isset($badge)
        <span class="nav-badge ml-auto">{{ $badge }}</span>
    @endisset
</a>
