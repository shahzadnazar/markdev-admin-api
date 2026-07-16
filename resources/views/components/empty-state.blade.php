@props(['icon' => 'inbox', 'title' => 'Nothing here yet', 'description' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-16 text-center']) }}>
    <div class="flex size-14 items-center justify-center rounded-2xl bg-primary/8 text-primary">
        <x-icon :name="$icon" class="size-7" />
    </div>
    <h3 class="mt-4 font-display text-lg font-semibold text-on-surface">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm leading-6 text-on-surface-variant">{{ $description }}</p>
    @endif
    @isset($action)
        <div class="mt-5">{{ $action }}</div>
    @endisset
</div>
