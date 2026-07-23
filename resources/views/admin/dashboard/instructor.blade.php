<x-admin.layout title="Dashboard">
    <x-page-header
        eyebrow="My classroom"
        :title="'Good '.(now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening')).', '.explode(' ', auth()->user()->name)[0]"
        description="Here's what's happening across your courses right now."
    />

    {{-- Headline counts --}}
    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-widget label="My courses" :value="number_format($stats['courses'])" icon="tag" tone="primary" />
        <x-stat-widget label="My students" :value="number_format($stats['students'])" icon="users" tone="secondary" />
        <x-stat-widget label="Pending grading" :value="number_format($stats['pending_grading'])"
            sub="submissions waiting for a score" icon="clipboard"
            :tone="$stats['pending_grading'] > 0 ? 'warning' : 'success'" />
        <x-stat-widget label="Attendance this month" :value="$stats['attendance_rate'] === null ? '—' : $stats['attendance_rate'].'%'"
            sub="present or late, your courses" icon="calendar"
            :tone="($stats['attendance_rate'] ?? 100) >= 75 ? 'success' : 'warning'" />
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-3">
        {{-- Enrollments sparkline (scoped to the instructor's courses) --}}
        <x-card class="xl:col-span-2">
            <div class="flex items-baseline justify-between gap-4">
                <div>
                    <p class="font-mono text-[11px] font-medium uppercase tracking-[0.12em] text-on-surface-variant">New students — last 14 days</p>
                    <p class="mt-1 font-display text-2xl font-bold text-on-surface">{{ $sparkline->sum('count') }}</p>
                </div>
                <a href="{{ route('admin.enrollments.index') }}" class="text-xs font-medium text-primary hover:underline">View all</a>
            </div>

            @php
                $max = max(1, $sparkline->max('count'));
                $w = 560; $h = 120; $pad = 6;
                $step = ($w - 2 * $pad) / max(1, $sparkline->count() - 1);
                $points = $sparkline->values()->map(fn ($d, $i) => [
                    'x' => round($pad + $i * $step, 1),
                    'y' => round($h - $pad - (($d['count'] / $max) * ($h - 2 * $pad - 14)), 1),
                    'label' => $d['label'],
                    'count' => $d['count'],
                ]);
                $polyline = $points->map(fn ($p) => $p['x'].','.$p['y'])->implode(' ');
                $area = "{$pad},".($h - $pad).' '.$polyline." ".($w - $pad).','.($h - $pad);
                $peak = $points->sortByDesc('count')->first();
            @endphp

            <svg viewBox="0 0 {{ $w }} {{ $h }}" class="mt-4 w-full" role="img" aria-label="Daily enrollments in your courses for the last 14 days">
                <defs>
                    <linearGradient id="spark-fill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#0C5ABD" stop-opacity="0.14" />
                        <stop offset="100%" stop-color="#0C5ABD" stop-opacity="0" />
                    </linearGradient>
                </defs>
                <line x1="{{ $pad }}" y1="{{ $h - $pad }}" x2="{{ $w - $pad }}" y2="{{ $h - $pad }}" stroke="#c2c6d5" stroke-width="1" />
                <polygon points="{{ $area }}" fill="url(#spark-fill)" />
                <polyline points="{{ $polyline }}" fill="none" stroke="#0C5ABD" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                @foreach ($points as $p)
                    <g>
                        <title>{{ $p['label'] }}: {{ $p['count'] }} enrollment{{ $p['count'] === 1 ? '' : 's' }}</title>
                        <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="8" fill="transparent" />
                        <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="2.5" fill="#0C5ABD" stroke="#ffffff" stroke-width="1.5" />
                    </g>
                @endforeach
                @if ($peak && $peak['count'] > 0)
                    <text x="{{ min(max($peak['x'], 18), $w - 18) }}" y="{{ max($peak['y'] - 10, 12) }}" text-anchor="middle" font-size="11" font-family="JetBrains Mono, monospace" fill="#424753">{{ $peak['count'] }}</text>
                @endif
            </svg>
            <div class="mt-1 flex justify-between font-mono text-[10px] uppercase tracking-[0.08em] text-outline">
                <span>{{ $sparkline->first()['label'] }}</span>
                <span>{{ $sparkline->last()['label'] }}</span>
            </div>
        </x-card>

        {{-- Upcoming schedule --}}
        <x-card>
            <div class="flex items-center justify-between">
                <p class="font-mono text-[11px] font-medium uppercase tracking-[0.12em] text-on-surface-variant">Upcoming schedule</p>
                <x-icon name="calendar" class="size-4.5 text-outline" />
            </div>
            <div class="mt-2">
                @forelse ($schedule as $event)
                    <div class="flex items-start gap-3 border-b border-surface-ice py-3 last:border-0">
                        <div class="flex size-10 shrink-0 flex-col items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <span class="font-mono text-[10px] uppercase leading-3">{{ $event->starts_at->format('M') }}</span>
                            <span class="font-display text-sm font-bold leading-4">{{ $event->starts_at->format('j') }}</span>
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-on-surface">{{ $event->title }}</p>
                            <p class="font-mono text-[11px] text-outline">
                                {{ $event->starts_at->format('D · g:i A') }}{{ $event->course ? ' · '.\Illuminate\Support\Str::limit($event->course->title, 22) : '' }}
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="py-8 text-center text-sm text-on-surface-variant">No upcoming sessions scheduled.</p>
                @endforelse
            </div>
        </x-card>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        {{-- Grading queue --}}
        <x-card :padding="false">
            <div class="flex items-center justify-between px-6 pb-2 pt-5">
                <p class="font-mono text-[11px] font-medium uppercase tracking-[0.12em] text-on-surface-variant">Grading queue</p>
                <a href="{{ route('admin.assignments.index') }}" class="text-xs font-medium text-primary hover:underline">All assignments</a>
            </div>
            @forelse ($gradingQueue as $submission)
                <a href="{{ route('admin.assignments.submissions', $submission->assignment) }}"
                    class="flex items-center gap-3 border-t border-surface-ice px-6 py-3.5 transition hover:bg-surface-ice/40">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-warning/20 to-primary/10 font-display text-sm font-semibold text-primary">
                        {{ strtoupper(mb_substr($submission->user?->name ?? '?', 0, 1)) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-on-surface">{{ $submission->user?->name ?? 'Deleted user' }}</p>
                        <p class="truncate text-xs text-on-surface-variant">{{ $submission->assignment?->title ?? '—' }}</p>
                    </div>
                    <p class="shrink-0 font-mono text-[11px] text-outline">{{ $submission->submitted_at?->diffForHumans(short: true) }}</p>
                </a>
            @empty
                <x-empty-state title="Nothing to grade" description="New submissions from your students will appear here." class="py-10" />
            @endforelse
        </x-card>

        {{-- Recent enrollments --}}
        <x-card :padding="false">
            <div class="flex items-center justify-between px-6 pb-2 pt-5">
                <p class="font-mono text-[11px] font-medium uppercase tracking-[0.12em] text-on-surface-variant">Newest students</p>
                <a href="{{ route('admin.enrollments.index') }}" class="text-xs font-medium text-primary hover:underline">View all</a>
            </div>
            @forelse ($recentEnrollments as $enrollment)
                <div class="flex items-center gap-3 border-t border-surface-ice px-6 py-3.5">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary/15 to-secondary/15 font-display text-sm font-semibold text-primary">
                        {{ strtoupper(mb_substr($enrollment->user?->name ?? '?', 0, 1)) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-on-surface">{{ $enrollment->user?->name ?? 'Deleted user' }}</p>
                        <p class="truncate text-xs text-on-surface-variant">{{ $enrollment->course?->title ?? '—' }}</p>
                    </div>
                    <p class="shrink-0 font-mono text-[11px] text-outline">{{ $enrollment->enrolled_at?->diffForHumans(short: true) }}</p>
                </div>
            @empty
                <x-empty-state title="No enrollments yet" description="Students enrolled in your courses will appear here." class="py-10" />
            @endforelse
        </x-card>
    </div>

    {{-- My courses --}}
    <div class="mt-5">
        <x-table>
            <thead class="bg-surface-ice/60">
                <tr>
                    <th class="th">Course</th>
                    <th class="th">Level</th>
                    <th class="th">Lessons</th>
                    <th class="th">Students</th>
                    <th class="th">Status</th>
                    <th class="th text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($courses as $course)
                    <tr class="row">
                        <td class="td max-w-[22rem]"><p class="truncate font-medium text-on-surface">{{ $course->title }}</p></td>
                        <td class="td"><x-badge variant="neutral">{{ $course->level }}</x-badge></td>
                        <td class="td font-mono text-xs text-on-surface-variant">{{ $course->lessons_count }}</td>
                        <td class="td font-mono text-xs text-on-surface-variant">{{ $course->enrollments_count }}</td>
                        <td class="td">
                            <x-badge :variant="['published' => 'success', 'draft' => 'neutral', 'archived' => 'neutral'][$course->status] ?? 'neutral'">
                                {{ $course->status }}
                            </x-badge>
                        </td>
                        <td class="td text-right">
                            <div class="inline-flex items-center gap-1">
                                <x-btn variant="ghost" size="sm" :href="route('admin.courses.show', $course)" title="Open curriculum">
                                    <x-icon name="eye" class="size-4" />
                                </x-btn>
                                @can('courses.update')
                                    <x-btn variant="ghost" size="sm" :href="route('admin.courses.edit', $course)" title="Edit">
                                        <x-icon name="pencil" class="size-4" />
                                    </x-btn>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty-state icon="tag" title="No courses assigned yet"
                        description="An administrator will assign you as the instructor of a course, or create your own." /></td></tr>
                @endforelse
            </tbody>
        </x-table>
    </div>
</x-admin.layout>
