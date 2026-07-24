@props(['counts', 'rate' => null])

{{-- Compact one-line day summary: colored dot, count, label per status. --}}
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-stretch divide-x divide-surface-ice overflow-hidden rounded-2xl bg-white shadow-card']) }}>
    @foreach ([
        ['key' => 'present', 'label' => 'Present', 'dot' => 'bg-success'],
        ['key' => 'late', 'label' => 'Late', 'dot' => 'bg-warning'],
        ['key' => 'absent', 'label' => 'Absent', 'dot' => 'bg-error'],
        ['key' => 'leave', 'label' => 'Leave', 'dot' => 'bg-secondary'],
    ] as $tile)
        <div class="flex min-w-28 flex-1 items-center gap-2.5 px-4 py-3">
            <span class="size-2 shrink-0 rounded-full {{ $tile['dot'] }}"></span>
            <p class="font-display text-lg font-bold leading-none text-on-surface">{{ number_format($counts[$tile['key']] ?? 0) }}</p>
            <p class="font-mono text-[10px] uppercase tracking-[0.08em] text-on-surface-variant">{{ $tile['label'] }}</p>
        </div>
    @endforeach

    @isset($counts['unmarked'])
        <div class="flex min-w-32 flex-1 items-center gap-2.5 px-4 py-3 {{ ($counts['unmarked'] ?? 0) > 0 ? 'bg-warning/[0.06]' : '' }}">
            <span class="size-2 shrink-0 rounded-full bg-outline/50"></span>
            <p class="font-display text-lg font-bold leading-none text-on-surface">{{ number_format($counts['unmarked']) }}</p>
            <p class="font-mono text-[10px] uppercase tracking-[0.08em] text-on-surface-variant">Not marked <span class="text-outline">/ {{ number_format($counts['total'] ?? 0) }}</span></p>
        </div>
    @endisset

    @if ($rate !== null)
        <div class="flex min-w-28 flex-1 items-center gap-2.5 bg-primary/[0.04] px-4 py-3">
            <span class="size-2 shrink-0 rounded-full bg-primary"></span>
            <p class="font-display text-lg font-bold leading-none text-primary">{{ $rate }}%</p>
            <p class="font-mono text-[10px] uppercase tracking-[0.08em] text-on-surface-variant">Attendance</p>
        </div>
    @endif
</div>
