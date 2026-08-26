@props(['progress' => null, 'duration' => 0])

@php
    /**
     * Timeline of one student's playback: filled where they watched, hollow
     * where they skipped. Reading the gaps is the point — a bare percentage
     * cannot tell "stopped half way" from "jumped over the middle".
     */
    $duration = (int) ($progress?->duration_seconds ?: $duration);
    $segments = $progress?->segments ?? [];
    $coverage = (int) ($progress?->coverage_percent ?? 0);
    $verdict = $progress?->verdict() ?? 'not started';

    $tone = match ($verdict) {
        'watched in full' => ['bg-success', 'text-success', 'success'],
        'skipped ahead' => ['bg-warning', 'text-warning', 'warning'],
        'left part-way' => ['bg-primary', 'text-primary', 'primary'],
        default => ['bg-outline-variant', 'text-outline', 'neutral'],
    };
@endphp

<div {{ $attributes->merge(['class' => 'min-w-0']) }}>
    <div class="flex items-center gap-2">
        <div class="relative h-2 flex-1 overflow-hidden rounded-full bg-surface-ice"
            role="img"
            aria-label="Watched {{ $coverage }} percent — {{ $verdict }}">
            @if ($duration > 0)
                @foreach ($segments as $segment)
                    @php
                        $left = max(0, min(100, $segment[0] / $duration * 100));
                        $width = max(0.5, min(100 - $left, ($segment[1] - $segment[0]) / $duration * 100));
                    @endphp
                    <span class="absolute inset-y-0 {{ $tone[0] }}"
                        style="left: {{ round($left, 2) }}%; width: {{ round($width, 2) }}%"></span>
                @endforeach
            @endif
        </div>
        <span class="w-9 shrink-0 text-right font-mono text-[11px] {{ $tone[1] }}">{{ $coverage }}%</span>
    </div>
    <p class="mt-1 font-mono text-[10px] uppercase tracking-[0.08em] text-outline">
        {{ $verdict }}
        @if ($duration > 0 && $progress)
            · {{ gmdate($duration >= 3600 ? 'H:i:s' : 'i:s', min($progress->watched_seconds, $duration)) }}
            of {{ gmdate($duration >= 3600 ? 'H:i:s' : 'i:s', $duration) }}
        @endif
    </p>
</div>
