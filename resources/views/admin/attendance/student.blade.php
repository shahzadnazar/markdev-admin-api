<x-admin.layout :title="$student->name.' — attendance'">
    @php
        $statusMeta = [
            'present' => ['label' => 'Present', 'badge' => 'success'],
            'late' => ['label' => 'Late', 'badge' => 'warning'],
            'absent' => ['label' => 'Absent', 'badge' => 'danger'],
            'leave' => ['label' => 'Leave', 'badge' => 'secondary'],
        ];
        $rangeMeta = [
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            'week' => 'This week',
            'month' => 'This month',
            'all' => 'All time',
        ];
        $rangeLabel = $range === 'custom'
            ? 'custom · '.(request('from') ?: '…').' → '.(request('to') ?: '…')
            : strtolower($rangeMeta[$range]);
        $roleLabel = fn ($user) => $user?->roles?->first()
            ? \Illuminate\Support\Str::headline($user->roles->first()->name)
            : null;
    @endphp

    {{-- Compact header: avatar + identity left, actions pinned top-right --}}
    <div class="flex items-start gap-3.5">
        @if ($student->avatar_url)
            <img src="{{ $student->avatar_url }}" alt="" style="width: 3rem; height: 3rem;"
                class="shrink-0 rounded-xl object-cover ring-1 ring-outline/20">
        @else
            <span style="width: 3rem; height: 3rem;"
                class="flex shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-primary to-secondary font-display text-lg font-bold text-white">
                {{ strtoupper(mb_substr($student->name, 0, 1)) }}
            </span>
        @endif
        <x-page-header class="min-w-0 flex-1" :title="$student->name"
            :description="'Attendance history — '.$rangeLabel.($statusFilter ? ', '.$statusFilter.' only' : '')"
            :crumbs="['Dashboard' => route('admin.dashboard'), 'Daily register' => route('admin.attendance.daily'), $student->name => null]">
            <x-slot:meta>
                @if ($student->studentProfile?->reg_no)
                    <x-badge variant="primary">{{ $student->studentProfile->reg_no }}</x-badge>
                @endif
            </x-slot:meta>
            <x-slot:actions>
                <x-btn variant="ghost" size="sm" :href="route('admin.attendance.daily')">
                    <x-icon name="arrow-left" class="size-4" /> Register
                </x-btn>
                @can('students.view')
                    <x-btn variant="ghost" size="sm" :href="route('admin.students.show', $student)">
                        <x-icon name="user-circle" class="size-4" /> Profile
                    </x-btn>
                @endcan
                <x-btn variant="secondary" size="sm" :href="route('admin.attendance.daily.show-print', array_merge(['student' => $student->id], request()->query()))" target="_blank">
                    <x-icon name="printer" class="size-4" /> Print PDF
                </x-btn>
            </x-slot:actions>
        </x-page-header>
    </div>

    {{-- Toolbar: range tabs + status filter + range overview in one card --}}
    <x-card :padding="false" class="mb-4">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-surface-ice px-4 py-2.5">
            {{-- Range buttons — real buttons, the active one filled --}}
            <div class="flex flex-wrap items-center gap-1.5">
                @foreach ($rangeMeta as $key => $label)
                    <a href="{{ route('admin.attendance.daily.show', array_filter(['student' => $student->id, 'range' => $key, 'status' => $statusFilter])) }}"
                        class="cursor-pointer rounded-lg border px-3 py-1.5 text-[13px] font-medium transition {{ $range === $key
                            ? 'border-primary bg-primary text-white shadow-card'
                            : 'border-outline/30 bg-white text-on-surface-variant hover:border-primary/50 hover:text-primary' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            {{-- Custom from → to range --}}
            <form method="GET" action="{{ route('admin.attendance.daily.show', $student) }}"
                class="flex items-center gap-1.5 rounded-lg border p-1 {{ $range === 'custom' ? 'border-primary bg-primary/[0.06]' : 'border-outline/30 bg-white' }}">
                <input type="hidden" name="range" value="custom">
                @if ($statusFilter)
                    <input type="hidden" name="status" value="{{ $statusFilter }}">
                @endif
                <span class="pl-1.5 font-mono text-[10px] uppercase tracking-[0.08em] {{ $range === 'custom' ? 'text-primary' : 'text-on-surface-variant' }}">Range</span>
                <label class="sr-only" for="from">From</label>
                <input type="date" name="from" id="from" value="{{ request('from') }}" max="{{ today()->toDateString() }}" class="field h-8 w-34 text-xs">
                <span class="text-xs text-outline">→</span>
                <label class="sr-only" for="to">To</label>
                <input type="date" name="to" id="to" value="{{ request('to') }}" max="{{ today()->toDateString() }}" class="field h-8 w-34 text-xs">
                <button type="submit"
                    class="cursor-pointer rounded-md border px-3 py-1.5 text-xs font-semibold transition {{ $range === 'custom'
                        ? 'border-primary bg-primary text-white'
                        : 'border-primary/40 bg-white text-primary hover:bg-primary/5' }}">
                    Go
                </button>
            </form>

            <form method="GET" action="{{ route('admin.attendance.daily.show', $student) }}" class="ml-auto">
                <input type="hidden" name="range" value="{{ $range }}">
                @if ($range === 'custom')
                    <input type="hidden" name="from" value="{{ request('from') }}">
                    <input type="hidden" name="to" value="{{ request('to') }}">
                @endif
                <label class="sr-only" for="status">Status</label>
                <select name="status" id="status" class="field h-9 w-36 text-sm" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    @foreach ($statusMeta as $key => $meta)
                        <option value="{{ $key }}" @selected($statusFilter === $key)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        <x-attendance.summary-strip :counts="$summary" :rate="$summary['rate']" class="px-4 py-2.5" />
    </x-card>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Date</th>
                <th class="th">Status</th>
                <th class="th">Remarks</th>
                <th class="th">Marked</th>
                <th class="th">Correction</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $record)
                <tr class="row">
                    <td class="td">
                        <p class="font-medium text-on-surface">{{ $record->date->format('D, M j, Y') }}</p>
                        <p class="font-mono text-[11px] text-outline">{{ $record->date->isToday() ? 'today' : $record->date->diffForHumans() }}</p>
                    </td>
                    <td class="td">
                        <x-badge :variant="$statusMeta[$record->status]['badge'] ?? 'neutral'">{{ $statusMeta[$record->status]['label'] ?? $record->status }}</x-badge>

                    </td>
                    <td class="td max-w-[16rem]">
                        <p class="whitespace-pre-line break-words text-sm text-on-surface-variant">{{ $record->remarks ?? '—' }}</p>
                    </td>
                    <td class="td">
                        <p class="font-mono text-xs {{ $record->status === 'late' ? 'font-semibold text-warning' : 'text-on-surface' }}">
                            {{ $record->arrived_at ? \Illuminate\Support\Carbon::parse($record->arrived_at)->format('g:i A') : $record->marked_at->format('g:i A') }}
                        </p>
                        <p class="font-mono text-[11px] text-outline">
                            @if ($record->source === 'biometric')
                                Biometric device
                            @else
                                @php $who = $record->marker?->name ?? '—'; $role = $roleLabel($record->marker); @endphp
                                {{ $who }}{{ $role && $role !== $who ? ' — '.$role : '' }}
                            @endif
                        </p>
                    </td>
                    <td class="td max-w-[16rem]">
                        @if ($record->last_updated_at)
                            <p class="whitespace-pre-line break-words text-xs text-on-surface">{{ $record->last_update_reason }}</p>
                            <p class="mt-0.5 font-mono text-[11px] text-outline">
                                {{ $record->last_updated_at->format('M j · g:i A') }} · {{ $record->updater?->name ?? '—' }}
                            </p>
                        @else
                            <span class="font-mono text-xs text-outline">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><x-empty-state icon="calendar" title="No attendance in this range"
                    description="Try a wider range, or clear the status filter." /></td></tr>
            @endforelse
        </tbody>
        @if ($records->hasPages() || $records->total() > 0)
            <x-slot:footer>
                {{ $records->links() }}
            </x-slot:footer>
        @endif
    </x-table>
</x-admin.layout>
