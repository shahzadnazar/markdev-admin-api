@props(['score', 'name' => null, 'compact' => false])

{{-- THE ONLY PLACE A DELIVERY SCORE IS RENDERED.

     A percentage on its own is not a fact about a person, it is a number
     somebody will quote in a meeting. It is never drawn without the counts
     that make it readable: how many stints it is computed from, how many were
     EARLY, how many late, how many days over, and how many days were spent
     blocked — because blocked days are the answer to "why is this low", and a
     score shown without them invites the wrong conversation.

     THE EARLY COUNT IS WHY THIS LIST HAS FIVE FIGURES AND NOT FOUR. The score
     caps at 100, so somebody early on every stint and somebody on time on every
     stint both read 100 and the percentage cannot tell them apart — see
     DeliveryScore::EARLY_MODES, whose `better` label promises only that early
     offsets late. This is the figure that separates them, and a scoreboard that
     wanted to rank two people both sitting at 100 had nothing to rank on.

     Below the minimum there is NO percentage at all. Null is "not enough
     completed work to say", which is a different statement from 0% and stays
     different all the way to the screen — one finished task must not decide
     whether somebody reads 0 or 100. --}}

@php
    $percent = $score['percent'] ?? null;
    $tone = match (true) {
        $percent === null => 'text-on-surface-variant',
        $percent >= 90 => 'text-success',
        $percent >= 70 => 'text-on-surface',
        default => 'text-error',
    };
@endphp

<div {{ $attributes->merge(['class' => 'min-w-0']) }}>
    @if ($name)
        <p class="truncate text-sm font-medium text-on-surface">{{ $name }}</p>
    @endif

    @if ($percent === null)
        <p class="mt-0.5 text-[13px] text-on-surface-variant">Not enough completed work yet</p>
        <p class="mt-0.5 font-mono text-[11px] text-outline">
            {{ $score['stints_completed'] }} of {{ $score['minimum'] }} finished stint{{ $score['minimum'] === 1 ? '' : 's' }}
        </p>
    @else
        <p class="mt-0.5 font-display text-2xl font-bold leading-7 tracking-[-0.02em] {{ $tone }}">{{ $percent }}%</p>
    @endif

    {{-- Always. Even beside a number, and even in the compact form. --}}
    <dl class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-[11px] text-outline">
        <div class="flex items-center gap-1">
            <dt class="sr-only">Stints completed</dt>
            <dd><span class="font-semibold text-on-surface-variant">{{ $score['stints_completed'] }}</span> completed</dd>
        </div>
        {{-- Before "late", so the two read as the pair they are. --}}
        <div class="flex items-center gap-1">
            <dt class="sr-only">Early</dt>
            <dd><span class="font-semibold {{ $score['early_count'] > 0 ? 'text-success' : 'text-on-surface-variant' }}">{{ $score['early_count'] }}</span> early</dd>
        </div>
        <div class="flex items-center gap-1">
            <dt class="sr-only">Late</dt>
            <dd><span class="font-semibold {{ $score['late_count'] > 0 ? 'text-error' : 'text-on-surface-variant' }}">{{ $score['late_count'] }}</span> late</dd>
        </div>
        <div class="flex items-center gap-1">
            <dt class="sr-only">Days over</dt>
            <dd><span class="font-semibold text-on-surface-variant">{{ $score['days_over'] }}</span> days over</dd>
        </div>
        <div class="flex items-center gap-1">
            <dt class="sr-only">Blocked days</dt>
            <dd><span class="font-semibold text-on-surface-variant">{{ $score['blocked_days'] }}</span> blocked</dd>
        </div>
    </dl>

    @unless ($compact)
        <p class="mt-2 max-w-md text-[11px] leading-4 text-outline">
            Day-weighted: each stint counts for the days it was given, so a long task delivered late
            outweighs several short ones delivered on time. Handovers count for neither side, and the
            percentage caps at 100 — the early count is what separates a perfect record from an
            early one.
        </p>
    @endunless
</div>
