{{-- One person's ledger. Read-only for them; only an admin may settle a row,
     and only an admin reaches anybody else's — a team-lead gets a 404. --}}
<x-admin.layout :title="$member->name.' — absence ledger'">
    <x-page-header eyebrow="Team" :title="$member->name"
        :description="'Absences beyond '.$allowance.' a month are charged at '.number_format($rate, 2).' each. This is a ledger, not a bill — there is no invoice behind it.'"
        :crumbs="['Dashboard' => \App\Support\PortalHome::url(), 'Absence ledger' => null]" />

    <x-form.errors-summary />

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Month</th>
                <th class="th td-num">Absences</th>
                <th class="th td-num">Chargeable</th>
                <th class="th td-num">Owed</th>
                <th class="th">Settled</th>
                @if ($maySettle)<th class="th text-right">Actions</th>@endif
            </tr>
        </thead>
        <tbody>
            @forelse ($fines as $fine)
                <tr class="row">
                    <td class="td"><span class="font-mono text-sm text-on-surface">{{ $fine->month->format('F Y') }}</span></td>
                    <td class="td td-num">{{ $fine->absences }}</td>
                    <td class="td td-num">
                        {{ $fine->chargeable }}
                        <p class="mt-0.5 font-mono text-[11px] text-outline">{{ $fine->allowance }} forgiven</p>
                    </td>
                    <td class="td td-num">
                        <span class="font-mono text-sm font-semibold text-on-surface">{{ number_format((float) $fine->total, 2) }}</span>
                        <p class="mt-0.5 font-mono text-[11px] text-outline">@ {{ number_format((float) $fine->rate, 2) }}</p>
                    </td>
                    <td class="td">
                        @if ($fine->isSettled())
                            <x-badge variant="success">{{ $fine->settled_on->format('j M Y') }}</x-badge>
                            <p class="mt-0.5 font-mono text-[11px] text-outline">{{ $fine->settler?->name }}</p>
                        @elseif ((float) $fine->total <= 0)
                            <x-badge variant="neutral">Nothing owed</x-badge>
                        @else
                            <x-badge variant="warning">Outstanding</x-badge>
                        @endif
                    </td>
                    @if ($maySettle)
                        <td class="td text-right">
                            @unless ($fine->isSettled())
                                <x-confirm-form :action="route('admin.team-fines.settle', $fine)" method="POST"
                                    title="Mark settled"
                                    :message="'Mark '.$member->name.'\'s '.$fine->month->format('F Y').' fine as settled? The row itself is never rewritten.'"
                                    confirm-label="Mark settled" variant="primary"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                    <x-icon name="check" class="size-4" />
                                </x-confirm-form>
                            @endunless
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $maySettle ? 6 : 5 }}">
                        <x-empty-state icon="banknotes" title="Nothing on the ledger"
                            description="Months are recorded at month end. A month with nothing owed still gets a row — settled at zero and never looked at have to be different." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-table>
</x-admin.layout>
