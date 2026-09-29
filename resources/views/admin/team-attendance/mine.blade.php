<x-admin.layout title="My attendance">
    <x-page-header eyebrow="Team" title="My attendance"
        :description="'Office starts at '.$officeStart.', with '.$grace.' minutes of grace.'"
        :crumbs="['Dashboard' => route('admin.dashboard'), 'My attendance' => null]" />

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
        <x-form.input type="month" label="Month" name="month" :value="$month->format('Y-m')" class="w-48" />
        <x-btn variant="secondary" size="md" type="submit">Show</x-btn>
    </form>

    <div class="mb-5 grid gap-4 sm:grid-cols-5">
        @foreach (['present' => 'Present', 'late' => 'Late', 'leave' => 'Leave', 'absent' => 'Absent'] as $status => $label)
            <x-card>
                <p class="font-display text-2xl font-bold leading-7 text-on-surface">{{ $counts[$status] ?? 0 }}</p>
                <p class="mt-0.5 text-[13px] text-on-surface-variant">{{ $label }}</p>
            </x-card>
        @endforeach
        <x-card>
            <p class="font-display text-2xl font-bold leading-7 text-on-surface">
                {{ $percent === null ? '—' : $percent.'%' }}
            </p>
            <p class="mt-0.5 text-[13px] text-on-surface-variant">Weighted</p>
        </x-card>
    </div>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Day</th>
                <th class="th">Status</th>
                <th class="th">Marked by</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $record)
                <tr class="row">
                    <td class="td"><span class="font-mono text-sm text-on-surface">{{ $record->date->format('j M Y') }}</span></td>
                    <td class="td">
                        <x-badge :variant="match ($record->status) {
                            'present' => 'success',
                            'late' => 'warning',
                            'absent' => 'danger',
                            'leave' => 'primary',
                            default => 'neutral',
                        }">{{ ucfirst($record->status) }}</x-badge>
                    </td>
                    <td class="td"><span class="text-sm text-on-surface-variant">{{ $record->marker?->name ?? 'System' }}</span></td>
                </tr>
            @empty
                <tr><td colspan="3"><x-empty-state icon="calendar" title="Nothing marked this month" /></td></tr>
            @endforelse
        </tbody>
    </x-table>
</x-admin.layout>
