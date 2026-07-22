<x-admin.layout title="Invoices">
    <x-page-header eyebrow="Finance" title="Invoices" description="Everything issued, paid, overdue or voided.">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.billing.plans.index')">Fee plans</x-btn>
            <x-btn variant="ghost" :href="route('admin.billing.transactions.index')">Transactions</x-btn>
            @can('billing.manage')
                <x-btn :href="route('admin.billing.invoices.create')">
                    <x-icon name="plus" class="size-4" /> New invoice
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar :action="route('admin.billing.invoices.index')">
        <div class="w-full sm:w-64">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Number or student…" class="field">
        </div>
        <div class="w-44">
            <x-form.label for="status" value="Status" />
            <select name="status" id="status" class="field">
                <option value="">All statuses</option>
                @foreach (['open', 'pending', 'paid', 'past_due', 'void'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>
                @endforeach
            </select>
        </div>
    </x-filter-bar>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Invoice</th>
                <th class="th">Student</th>
                <th class="th">Amount</th>
                <th class="th">Issued</th>
                <th class="th">Due</th>
                <th class="th">Status</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($invoices as $invoice)
                <tr class="row">
                    <td class="td">
                        <a href="{{ route('admin.billing.invoices.show', $invoice) }}" class="font-mono text-xs font-medium text-primary hover:underline">{{ $invoice->number }}</a>
                        @if ($invoice->title)
                            <p class="truncate text-xs text-outline">{{ $invoice->title }}</p>
                        @endif
                    </td>
                    <td class="td">
                        <p class="font-medium text-on-surface">{{ $invoice->user?->name ?? 'Deleted user' }}</p>
                    </td>
                    <td class="td font-mono text-sm text-on-surface">{{ $invoice->currency }} {{ number_format((float) $invoice->amount, 2) }}</td>
                    <td class="td font-mono text-xs text-outline">{{ $invoice->issued_at?->format('M j, Y') }}</td>
                    <td class="td font-mono text-xs {{ $invoice->status === 'past_due' ? 'text-error' : 'text-outline' }}">{{ $invoice->due_at?->format('M j, Y') ?? '—' }}</td>
                    <td class="td">
                        <x-badge :variant="['open' => 'primary', 'pending' => 'warning', 'paid' => 'success', 'past_due' => 'danger', 'void' => 'neutral'][$invoice->status] ?? 'neutral'">
                            {{ str_replace('_', ' ', $invoice->status) }}
                        </x-badge>
                    </td>
                    <td class="td text-right">
                        <x-btn variant="ghost" size="sm" :href="route('admin.billing.invoices.show', $invoice)">
                            <x-icon name="eye" class="size-4" />
                        </x-btn>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty-state icon="banknotes" title="No invoices" description="Issue an invoice against a student's fee plan." /></td></tr>
            @endforelse
        </tbody>
        @if ($invoices->hasPages())
            <x-slot:footer>{{ $invoices->links() }}</x-slot:footer>
        @endif
    </x-table>
</x-admin.layout>
