<x-admin.layout title="Attendance log">
    <x-page-header eyebrow="Learning" title="Attendance log" description="Recent attendance records across all courses.">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.attendance.index')">
                <x-icon name="arrow-left" class="size-4" /> Mark attendance
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar :action="route('admin.attendance.log')">
        <div class="w-64">
            <x-form.label for="course" value="Course" />
            <select name="course" id="course" class="field">
                <option value="">All courses</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected(request('course') == $course->id)>{{ $course->title }}</option>
                @endforeach
            </select>
        </div>
        <div class="w-44">
            <x-form.label for="status" value="Status" />
            <select name="status" id="status" class="field">
                <option value="">All statuses</option>
                @foreach (['present', 'late', 'absent', 'excused'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
    </x-filter-bar>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Date</th>
                <th class="th">Student</th>
                <th class="th">Course / session</th>
                <th class="th">Status</th>
                <th class="th">Recorded by</th>
                <th class="th">Notes</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $record)
                <tr class="row">
                    <td class="td font-mono text-xs text-outline">{{ $record->date?->format('M j, Y') }}</td>
                    <td class="td">
                        <p class="font-medium text-on-surface">{{ $record->user?->name ?? 'Deleted user' }}</p>
                    </td>
                    <td class="td max-w-[16rem]">
                        <p class="truncate text-on-surface-variant">{{ $record->course?->title ?? '—' }}</p>
                        @if ($record->session_title)
                            <p class="truncate text-xs text-outline">{{ $record->session_title }}</p>
                        @endif
                    </td>
                    <td class="td">
                        <x-badge :variant="['present' => 'success', 'late' => 'warning', 'absent' => 'danger', 'excused' => 'neutral'][$record->status] ?? 'neutral'">
                            {{ $record->status }}
                        </x-badge>
                        @if ($record->source === 'biometric')
                            <x-badge variant="secondary" class="ml-1" title="Marked by a biometric device">device</x-badge>
                        @endif
                    </td>
                    <td class="td text-xs text-on-surface-variant">{{ $record->recorder?->name ?? '—' }}</td>
                    <td class="td max-w-[14rem]"><p class="truncate text-xs text-outline" title="{{ $record->notes }}">{{ $record->notes ?? '—' }}</p></td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty-state icon="calendar" title="No records found" description="Adjust the filters, or mark attendance for a course." /></td></tr>
            @endforelse
        </tbody>
        @if ($records->hasPages())
            <x-slot:footer>{{ $records->links() }}</x-slot:footer>
        @endif
    </x-table>
</x-admin.layout>
