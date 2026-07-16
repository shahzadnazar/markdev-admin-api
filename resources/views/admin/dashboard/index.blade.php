<x-admin.layout title="Dashboard">
    <x-page-header
        eyebrow="Overview"
        :title="'Good '.(now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening')).', '.explode(' ', auth()->user()->name)[0]"
        description="Here's what's happening across the academy right now."
    />

    {{-- Headline counts --}}
    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-widget label="Students" :value="number_format($stats['students'])" icon="users" tone="primary" />
        <x-stat-widget label="Instructors" :value="number_format($stats['instructors'])" icon="academic-cap" tone="secondary" />
        <x-stat-widget label="Courses" :value="number_format($stats['courses'])" icon="tag" tone="primary" />
        <x-stat-widget label="Lessons" :value="number_format($stats['lessons'])" icon="play" tone="secondary" />
    </div>

    {{-- Action metrics --}}
    <div class="mt-5 grid gap-5 sm:grid-cols-3">
        <x-stat-widget label="Pending grading" :value="number_format($stats['pending_submissions'])" sub="submissions waiting for a score" icon="clipboard" :tone="$stats['pending_submissions'] > 0 ? 'warning' : 'success'" />
        <x-stat-widget label="Quiz attempts today" :value="number_format($stats['attempts_today'])" sub="since midnight" icon="quiz" tone="primary" />
        <x-stat-widget label="Attendance this month" :value="$stats['attendance_rate'] === null ? '—' : $stats['attendance_rate'].'%'" sub="present or late, all courses" icon="calendar" :tone="($stats['attendance_rate'] ?? 100) >= 75 ? 'success' : 'warning'" />
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-3">
        {{-- Enrollments sparkline --}}
        <x-card class="xl:col-span-2">
            <div class="flex items-baseline justify-between gap-4">
                <div>
                    <p class="font-mono text-[11px] font-medium uppercase tracking-[0.12em] text-on-surface-variant">Enrollments — last 14 days</p>
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

            <svg viewBox="0 0 {{ $w }} {{ $h }}" class="mt-4 w-full" role="img" aria-label="Daily enrollments for the last 14 days">
                <defs>
                    <linearGradient id="spark-fill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#0C5ABD" stop-opacity="0.14" />
                        <stop offset="100%" stop-color="#0C5ABD" stop-opacity="0" />
                    </linearGradient>
                </defs>
                {{-- baseline --}}
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

        {{-- System health --}}
        <x-card>
            <div class="flex items-center justify-between">
                <p class="font-mono text-[11px] font-medium uppercase tracking-[0.12em] text-on-surface-variant">System health</p>
                <x-icon name="server" class="size-4.5 text-outline" />
            </div>

            <dl class="mt-4 space-y-3.5 text-sm">
                <div class="flex items-center justify-between">
                    <dt class="text-on-surface-variant">Database</dt>
                    <dd>
                        <x-badge :variant="$health['db_ok'] ? 'success' : 'danger'">{{ $health['db_ok'] ? 'Connected' : 'Down' }}</x-badge>
                    </dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-on-surface-variant">Queue pending</dt>
                    <dd class="font-mono text-xs text-on-surface">{{ number_format($health['queue_pending']) }} job{{ $health['queue_pending'] === 1 ? '' : 's' }}</dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-on-surface-variant">Failed jobs</dt>
                    <dd>
                        @if ($health['queue_failed'] > 0)
                            <x-badge variant="danger">{{ number_format($health['queue_failed']) }}</x-badge>
                        @else
                            <span class="font-mono text-xs text-on-surface">0</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <div class="flex items-center justify-between">
                        <dt class="text-on-surface-variant">Storage</dt>
                        <dd class="font-mono text-xs text-on-surface">
                            @if ($health['disk_used_percent'] !== null)
                                {{ $health['disk_used_percent'] }}% used
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    @if ($health['disk_used_percent'] !== null)
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-surface-ice">
                            <div class="h-full rounded-full bg-gradient-to-r from-primary to-secondary" style="width: {{ min(100, $health['disk_used_percent']) }}%"></div>
                        </div>
                        <p class="mt-1.5 text-[11px] text-outline">{{ \Illuminate\Support\Number::fileSize($health['disk_free'] ?? 0) }} free of {{ \Illuminate\Support\Number::fileSize($health['disk_total'] ?? 0) }}</p>
                    @endif
                </div>
                <div class="flex items-center justify-between border-t border-surface-ice pt-3.5">
                    <dt class="text-on-surface-variant">Last backup</dt>
                    <dd class="font-mono text-xs text-on-surface">{{ $health['last_backup']?->diffForHumans() ?? 'never' }}</dd>
                </div>
            </dl>
        </x-card>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        {{-- Recent enrollments --}}
        <x-card :padding="false">
            <div class="flex items-center justify-between px-6 pb-2 pt-5">
                <p class="font-mono text-[11px] font-medium uppercase tracking-[0.12em] text-on-surface-variant">Recent enrollments</p>
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
                <x-empty-state title="No enrollments yet" description="New sign-ups will appear here." class="py-10" />
            @endforelse
        </x-card>

        {{-- Latest audit activity --}}
        <x-card :padding="false">
            <div class="flex items-center justify-between px-6 pb-2 pt-5">
                <p class="font-mono text-[11px] font-medium uppercase tracking-[0.12em] text-on-surface-variant">Latest audit activity</p>
                @can('audit-logs.view')
                    <a href="{{ route('admin.audit-logs.index') }}" class="text-xs font-medium text-primary hover:underline">Open audit logs</a>
                @endcan
            </div>
            @forelse ($latestLogs as $log)
                <div class="flex items-center gap-3 border-t border-surface-ice px-6 py-3">
                    <span class="w-24 shrink-0 font-mono text-[11px] text-outline">{{ $log->created_at?->format('M j H:i') }}</span>
                    <span class="min-w-0 flex-1 truncate text-sm text-on-surface">
                        <span class="font-medium">{{ $log->user_name }}</span>
                        <span class="text-on-surface-variant">· {{ str_replace('_', ' ', $log->module) }}</span>
                    </span>
                    <x-badge :variant="match($log->action) {
                        'created', 'restored' => 'success',
                        'updated', 'graded', 'attendance_marked' => 'primary',
                        'deleted', 'force_deleted', 'login_failed' => 'danger',
                        'exported', 'backup_queued' => 'secondary',
                        default => 'neutral',
                    }">{{ str_replace('_', ' ', $log->action) }}</x-badge>
                </div>
            @empty
                <x-empty-state title="No audit entries" description="System activity will be recorded here." class="py-10" />
            @endforelse
        </x-card>
    </div>
</x-admin.layout>
