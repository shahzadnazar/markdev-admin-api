<x-table>
    <thead class="bg-surface-ice/60">
        <tr>
            <th class="th">Reference</th>
            <th class="th">Student</th>
            <th class="th">Invoice</th>
            <th class="th">Method</th>
            <th class="th td-num">Amount</th>
            <th class="th">Status</th>
            <th class="th">When</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($transactions as $transaction)
            <tr class="row">
                <td class="td font-mono text-xs text-primary">{{ $transaction->reference }}</td>
                <td class="td"><p class="font-medium text-on-surface">{{ $transaction->user?->name ?? 'Deleted user' }}</p></td>
                <td class="td">
                    @if ($transaction->invoice)
                        <a href="{{ route('admin.billing.invoices.show', $transaction->invoice) }}" class="font-mono text-xs text-on-surface-variant hover:text-primary hover:underline">{{ $transaction->invoice->number }}</a>
                    @else
                        <span class="text-xs text-outline">—</span>
                    @endif
                </td>
                <td class="td text-sm text-on-surface-variant">
                    {{ $transaction->method_brand ? $transaction->method_brand.' •••• '.$transaction->method_last4 : str_replace('_', ' ', ucfirst($transaction->method_type)) }}
                </td>
                <td class="td td-num font-mono text-sm text-on-surface">{{ $transaction->currency }} {{ number_format((float) $transaction->amount, 2) }}</td>
                <td class="td">
                    <x-badge :variant="['success' => 'success', 'pending' => 'warning', 'rejected' => 'danger', 'failed' => 'danger', 'refunded' => 'neutral'][$transaction->status] ?? 'neutral'">
                        {{ $transaction->status }}
                    </x-badge>
                </td>
                <td class="td font-mono text-xs text-outline">{{ $transaction->created_at?->format('j M Y · H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="7"><x-empty-state icon="banknotes" title="No transactions" description="Payments recorded manually or made by students will appear here." /></td></tr>
        @endforelse
    </tbody>
    @if ($transactions->hasPages())
        <x-slot:footer><div data-pagination>{{ $transactions->links() }}</div></x-slot:footer>
    @endif
</x-table>
