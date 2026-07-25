@props(['eyebrow' => null, 'title', 'description' => null, 'crumbs' => []])

{{-- The one page-header pattern: breadcrumb (or eyebrow) · 24px title ·
     13px functional description · actions pinned top-right. --}}
<div {{ $attributes->merge(['class' => 'mb-5 flex flex-wrap items-start justify-between gap-x-4 gap-y-3 sm:flex-nowrap']) }}>
    <div class="min-w-0">
        @if (count($crumbs))
            <nav aria-label="Breadcrumb" class="mb-1">
                <ol class="flex flex-wrap items-center gap-1.5 font-mono text-[11px] font-medium uppercase tracking-[0.08em] text-outline">
                    @foreach ($crumbs as $label => $url)
                        <li class="flex items-center gap-1.5">
                            @if ($url)
                                <a href="{{ $url }}" class="rounded-sm transition hover:text-primary">{{ $label }}</a>
                            @else
                                <span aria-current="page" class="text-on-surface-variant">{{ $label }}</span>
                            @endif
                            @unless ($loop->last)<span aria-hidden="true">/</span>@endunless
                        </li>
                    @endforeach
                </ol>
            </nav>
        @elseif ($eyebrow)
            <p class="eyebrow mb-1">{{ $eyebrow }}</p>
        @endif
        <div class="flex min-w-0 flex-wrap items-center gap-x-2.5">
            <h1 class="truncate font-display text-2xl font-bold leading-8 tracking-[-0.02em] text-on-surface">{{ $title }}</h1>
            @isset($meta){{ $meta }}@endisset
        </div>
        @if ($description)
            <p class="mt-0.5 max-w-2xl text-[13px] leading-5 text-on-surface-variant">{{ $description }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2 pt-1.5">{{ $actions }}</div>
    @endisset
</div>
