{{-- ADMIN ONLY. A team-lead sees no fine figure anywhere. --}}
<x-admin.layout title="Team absence ledger">
    <x-page-header eyebrow="Team" title="Absence ledger"
        description="What each member owes for absences beyond their monthly allowance. A ledger, not a bill."
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Absence ledger' => null]" />

    <x-form.errors-summary />

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Member</th>
                <th class="th">Month</th>
                <th class="th td-num">Absences</th>
                <th class="th td-num">Owed</th>
                <th class="th">Settled</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($fines as $fine)
                <tr class="row">
                    <td class="td">
                        <a href="{{ route('admin.team-fines.show', $fine->user) }}" class="font-medium text-on-surface hover:text-primary">{{ $fine->user?->name }}</a>
                    </td>
                    <td class="td"><span class="font-mono text-sm text-on-surface-variant">{{ $fine->month->format('F Y') }}</span></td>
                    <td class="td td-num">{{ $fine->absences }}</td>
                    <td class="td td-num"><span class="font-mono text-sm font-semibold text-on-surface">{{ number_format((float) $fine->total, 2) }}</span></td>
                    <td class="td">
                        @if ($fine->isSettled())
                            <x-badge variant="success">Settled</x-badge>
                        @elseif ((float) $fine->total <= 0)
                            <x-badge variant="neutral">Nothing owed</x-badge>
                        @else
                            <x-badge variant="warning">Outstanding</x-badge>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><x-empty-state icon="banknotes" title="Nothing on the ledger yet" /></td></tr>
            @endforelse
        </tbody>
    </x-table>
</x-admin.layout>
