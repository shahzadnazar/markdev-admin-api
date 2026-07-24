@props(['counts', 'rate' => null])

{{-- Slim inline day summary: dot + count + label per status. No card of its
     own — embed it inside a toolbar card or any container. --}}
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-x-5 gap-y-1.5']) }}>
    @foreach ([
        ['key' => 'present', 'label' => 'Present', 'dot' => 'bg-success'],
        ['key' => 'late', 'label' => 'Late', 'dot' => 'bg-warning'],
        ['key' => 'absent', 'label' => 'Absent', 'dot' => 'bg-error'],
        ['key' => 'leave', 'label' => 'Leave', 'dot' => 'bg-secondary'],
    ] as $tile)
        <span class="inline-flex items-center gap-1.5">
            <span class="size-2 shrink-0 rounded-full {{ $tile['dot'] }}"></span>
            <span class="font-display text-sm font-bold leading-none text-on-surface">{{ number_format($counts[$tile['key']] ?? 0) }}</span>
            <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-on-surface-variant">{{ $tile['label'] }}</span>
        </span>
    @endforeach

    @isset($counts['unmarked'])
        <span class="inline-flex items-center gap-1.5 {{ ($counts['unmarked'] ?? 0) > 0 ? 'rounded-full bg-warning/10 px-2.5 py-1' : '' }}">
            <span class="size-2 shrink-0 rounded-full bg-outline/50"></span>
            <span class="font-display text-sm font-bold leading-none text-on-surface">{{ number_format($counts['unmarked']) }}</span>
            <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-on-surface-variant">Not marked <span class="text-outline">/ {{ number_format($counts['total'] ?? 0) }}</span></span>
        </span>
    @endisset

    @if ($rate !== null)
        <span class="inline-flex items-center gap-1.5 rounded-full bg-primary/[0.06] px-2.5 py-1">
            <span class="size-2 shrink-0 rounded-full bg-primary"></span>
            <span class="font-display text-sm font-bold leading-none text-primary">{{ $rate }}%</span>
            <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-on-surface-variant">Attendance</span>
        </span>
    @endif
</div>
