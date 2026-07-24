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
        $roleLabel = fn ($user) => $user?->roles?->first()
            ? \Illuminate\Support\Str::headline($user->roles->first()->name)
            : null;
    @endphp

    <x-page-header eyebrow="Learning · Daily attendance" :title="$student->name"
        :description="($student->studentProfile?->reg_no ? 'Reg # '.$student->studentProfile->reg_no.' · ' : '').'Attendance history — '.strtolower($rangeMeta[$range])">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.attendance.daily')">
                <x-icon name="arrow-left" class="size-4" /> Register
            </x-btn>
            @can('students.view')
                <x-btn variant="ghost" :href="route('admin.students.show', $student)">
                    <x-icon name="user-circle" class="size-4" /> Profile
                </x-btn>
            @endcan
            <x-btn variant="secondary" :href="route('admin.attendance.daily.show-print', array_merge(['student' => $student->id], request()->query()))" target="_blank">
                <x-icon name="printer" class="size-4" /> Print PDF
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    {{-- Compact overview for the selected range --}}
    <x-attendance.summary-strip :counts="$summary" :rate="$summary['rate']" class="mb-5" />

    {{-- Range tabs + status filter --}}
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex rounded-lg bg-white p-1 shadow-card">
            @foreach ($rangeMeta as $key => $label)
                <a href="{{ route('admin.attendance.daily.show', array_filter(['student' => $student->id, 'range' => $key, 'status' => $statusFilter])) }}"
                    class="rounded-md px-3.5 py-1.5 text-sm font-medium transition {{ $range === $key ? 'bg-primary/10 text-primary' : 'text-on-surface-variant hover:text-on-surface' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('admin.attendance.daily.show', $student) }}" class="flex items-center gap-2">
            <input type="hidden" name="range" value="{{ $range }}">
            <select name="status" class="field h-9 w-36 text-sm" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach ($statusMeta as $key => $meta)
                    <option value="{{ $key }}" @selected($statusFilter === $key)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
        </form>
    </div>

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
                    <td class="td max-w-[14rem]">
                        <p class="truncate text-sm text-on-surface-variant" title="{{ $record->remarks }}">{{ $record->remarks ?? '—' }}</p>
                    </td>
                    <td class="td">
                        <p class="font-mono text-xs text-on-surface">{{ $record->marked_at->format('g:i A') }}</p>
                        <p class="font-mono text-[11px] text-outline">
                            @if ($record->source === 'biometric')
                                Biometric device
                            @else
                                {{ $record->marker?->name ?? '—' }}{{ $roleLabel($record->marker) ? ' — '.$roleLabel($record->marker) : '' }}
                            @endif
                        </p>
                    </td>
                    <td class="td max-w-[16rem]">
                        @if ($record->last_updated_at)
                            <p class="text-xs text-on-surface">{{ $record->last_update_reason }}</p>
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
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-outline">
                        Showing <span class="font-semibold text-on-surface">{{ $records->firstItem() ?? 0 }}–{{ $records->lastItem() ?? 0 }}</span>
                        of <span class="font-semibold text-on-surface">{{ $records->total() }}</span> day(s)
                    </p>
                    {{ $records->links() }}
                </div>
            </x-slot:footer>
        @endif
    </x-table>
</x-admin.layout>
