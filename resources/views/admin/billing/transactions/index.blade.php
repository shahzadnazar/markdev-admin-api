<x-admin.layout title="Transactions">
    <x-page-header eyebrow="Finance" title="Transactions" description="Every payment attempt across the platform.">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.billing.plans.index')">Fee plans</x-btn>
            <x-btn variant="ghost" :href="route('admin.billing.invoices.index')">Invoices</x-btn>
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar :action="route('admin.billing.transactions.index')">
        <div class="w-full sm:w-64">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Reference or student…" class="field">
        </div>
        <div class="w-44">
            <x-form.label for="status" value="Status" />
            <select name="status" id="status" class="field">
                <option value="">All statuses</option>
                @foreach (['pending', 'success', 'rejected', 'failed', 'refunded'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
    </x-filter-bar>

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
                    <td class="td font-mono text-xs text-outline">{{ $transaction->created_at?->format('M j, Y · H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty-state icon="banknotes" title="No transactions" description="Payments recorded manually or made by students will appear here." /></td></tr>
            @endforelse
        </tbody>
        @if ($transactions->hasPages())
            <x-slot:footer>{{ $transactions->links() }}</x-slot:footer>
        @endif
    </x-table>
</x-admin.layout>
