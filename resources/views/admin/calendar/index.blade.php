{{-- The month, derived. Nothing on this screen is stored anywhere as a calendar
     row: each entry is a project, a milestone, a task, an approved leave day or
     an academy holiday, read where it actually lives. See App\Support\TeamCalendar.

     No Dashboard crumb: `admin.dashboard` is an academy route and refuses a
     team-lead and a team member. --}}
<x-admin.layout title="Calendar">
    <x-page-header eyebrow="Team" :title="$month->format('F Y')"
        description="Project and task dates, milestones, approved leave and academy holidays."
        :crumbs="['Calendar' => null]">
        <x-slot:actions>
            {{-- The toggle. A link per option rather than a form, so a month and
                 a scope are both in the URL and a view is shareable. --}}
            <div class="flex items-center rounded-lg border border-outline-variant/60 bg-white p-0.5">
                @foreach ($scopes as $key => $label)
                    <a href="{{ route('admin.calendar.index', ['month' => $month->format('Y-m'), 'scope' => $key]) }}"
                        @if ($key === $scope) aria-current="true" @endif
                        class="rounded-md px-3 py-1.5 text-xs font-medium transition {{ $key === $scope ? 'bg-primary text-white' : 'text-on-surface-variant hover:text-primary' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <div class="flex items-center gap-1">
                <x-btn variant="ghost" size="sm" :href="route('admin.calendar.index', ['month' => $month->copy()->subMonth()->format('Y-m'), 'scope' => $scope])" aria-label="Previous month">
                    <x-icon name="arrow-left" class="size-4" />
                </x-btn>
                <x-btn variant="ghost" size="sm" :href="route('admin.calendar.index', ['scope' => $scope])">Today</x-btn>
                <x-btn variant="ghost" size="sm" :href="route('admin.calendar.index', ['month' => $month->copy()->addMonth()->format('Y-m'), 'scope' => $scope])" aria-label="Next month">
                    <x-icon name="chevron-right" class="size-4" />
                </x-btn>
            </div>
        </x-slot:actions>
    </x-page-header>

    @if ($legend !== [])
        {{-- Colour is never the only signal: the swatch is named here and every
             entry in the grid carries its own words. --}}
        <div class="mb-4 flex flex-wrap items-center gap-x-5 gap-y-2">
            @foreach ($legend as $key)
                <span class="inline-flex items-center gap-2 text-[11px] font-medium text-on-surface-variant">
                    <span class="size-2.5 shrink-0 rounded-full ring-1 ring-inset ring-black/10" style="background-color: {{ $key['colour'] ?: '#727784' }}"></span>
                    {{ $key['label'] }}
                </span>
            @endforeach
        </div>
    @endif

    <x-card class="p-0">
        <div class="grid grid-cols-7 border-b border-surface-ice">
            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                <div class="px-2 py-2 text-center font-mono text-[10px] font-medium uppercase tracking-[0.12em] text-outline">{{ $weekday }}</div>
            @endforeach
        </div>

        <div class="grid grid-cols-7">
            @php $cursor = $gridStart->copy(); @endphp
            @while ($cursor->lessThanOrEqualTo($gridEnd))
                @php
                    $key = $cursor->toDateString();
                    $entries = $days->get($key, []);
                    $outside = $cursor->month !== $month->month;
                    $isToday = $cursor->isToday();
                @endphp
                <div class="min-h-[104px] border-b border-r border-surface-ice/70 p-1.5 last:border-r-0 {{ $outside ? 'bg-surface-ice/40' : '' }}">
                    <p class="mb-1 px-1 font-mono text-[11px] {{ $isToday ? 'font-bold text-primary' : ($outside ? 'text-outline/60' : 'text-outline') }}">
                        {{ $cursor->day }}
                        @if ($isToday)
                            <span class="sr-only">(today)</span>
                        @endif
                    </p>

                    <div class="space-y-1">
                        @foreach ($entries as $entry)
                            @php $chip = 'block truncate rounded-md px-1.5 py-1 text-[11px] leading-4'; @endphp
                            @if ($entry['url'])
                                <a href="{{ $entry['url'] }}" class="{{ $chip }} transition hover:brightness-95"
                                    style="background-color: {{ $entry['colour'] ?: '#727784' }}1a; color: {{ $entry['colour'] ?: '#727784' }}"
                                    title="{{ $entry['label'] }}{{ $entry['note'] ? ' — '.$entry['note'] : '' }}">
                                    {{ $entry['label'] }}
                                </a>
                            @else
                                <span class="{{ $chip }}"
                                    style="background-color: {{ $entry['colour'] ?: '#727784' }}1a; color: {{ $entry['colour'] ?: '#727784' }}"
                                    title="{{ $entry['label'] }}{{ $entry['note'] ? ' — '.$entry['note'] : '' }}">
                                    {{ $entry['label'] }}
                                </span>
                            @endif
                        @endforeach
                    </div>
                </div>
                @php $cursor->addDay(); @endphp
            @endwhile
        </div>
    </x-card>

    @if ($days->isEmpty())
        <p class="mt-4 text-center text-[13px] text-on-surface-variant">
            Nothing dated in {{ $month->format('F Y') }} under "{{ $scopes[$scope] }}".
        </p>
    @endif
</x-admin.layout>
