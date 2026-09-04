<x-admin.layout :title="'Invoice '.$invoice->number">
    <x-page-header
        eyebrow="Finance"
        :title="$invoice->number"
        :description="$invoice->title"
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Invoices' => route('admin.billing.invoices.index'), $invoice->number => null]"
    >
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.billing.invoices.index')">
                <x-icon name="arrow-left" class="size-4" /> All invoices
            </x-btn>
            @can('billing.manage')
                @if (! in_array($invoice->status, ['paid', 'void'], true))
                    <x-confirm-form
                        :action="route('admin.billing.invoices.void', $invoice)"
                        title="Void this invoice?"
                        message="A voided invoice can no longer be paid."
                        confirm-label="Void invoice"
                        class="inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-error transition hover:bg-error/10"
                    >
                        <x-icon name="archive" class="size-4" /> Void
                    </x-confirm-form>
                @endif
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($invoice->status === 'pending')
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-warning/30 bg-warning-container/50 px-5 py-4">
            <p class="text-sm text-on-surface">
                <span class="font-semibold">A student fee submission is awaiting review for this invoice.</span>
                Verify the receipt, then approve or reject it.
            </p>
            <x-btn size="sm" :href="route('admin.billing.submissions')">Review submission</x-btn>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
        <div class="space-y-6">
            {{-- Invoice summary --}}
            <x-card>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="eyebrow">Billed to</p>
                        <p class="mt-1 font-display text-lg font-semibold text-on-surface">{{ $invoice->user?->name ?? 'Deleted user' }}</p>
                        <p class="text-sm text-on-surface-variant">{{ $invoice->user?->email }}</p>
                        @if ($invoice->feePlan)
                            <p class="mt-2 text-xs text-outline">Plan: {{ $invoice->feePlan->title }}{{ $invoice->feePlan->course ? ' · '.$invoice->feePlan->course->title : '' }}</p>
                        @endif
                    </div>
                    <x-badge :variant="['upcoming' => 'neutral', 'open' => 'primary', 'pending' => 'warning', 'paid' => 'success', 'past_due' => 'danger', 'void' => 'neutral'][$invoice->status] ?? 'neutral'" class="text-xs">
                        {{ str_replace('_', ' ', $invoice->status) }}
                    </x-badge>
                </div>

                <dl class="mt-6 grid gap-6 border-t border-surface-ice pt-6 sm:grid-cols-4">
                    <div>
                        <dt class="font-mono text-[11px] uppercase tracking-[0.12em] text-on-surface-variant">Amount</dt>
                        <dd class="mt-1 font-display text-2xl font-bold text-on-surface">
                            {{ $invoice->currency }} {{ number_format((float) $invoice->payable_total, 2) }}
                            {{-- Each charge on its own line: the two fines are
                                 different charges for different reasons and a
                                 single "incl. fines" would hide which. --}}
                            <span class="block font-sans text-xs font-normal text-on-surface-variant">
                                {{ number_format((float) $invoice->amount, 0) }} tuition
                            </span>
                            @if ((float) $invoice->fine_amount > 0)
                                <span class="block font-sans text-xs font-normal text-error">
                                    + {{ number_format((float) $invoice->fine_amount, 0) }} late-payment fine ({{ $invoice->fine_days }} days)
                                </span>
                            @endif
                            @if ((float) $invoice->absence_fine_amount > 0)
                                <span class="block font-sans text-xs font-normal text-error">
                                    + {{ number_format((float) $invoice->absence_fine_amount, 0) }} absence fine
                                </span>
                            @endif
                            @if ((float) $invoice->absence_fine_credit > 0)
                                <span class="block font-sans text-xs font-normal text-success">
                                    − {{ number_format((float) $invoice->absence_fine_credit, 0) }} credit — corrected absence
                                </span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="font-mono text-[11px] uppercase tracking-[0.12em] text-on-surface-variant">Issued</dt>
                        <dd class="mt-1 text-sm text-on-surface">{{ $invoice->issued_at?->format('M j, Y') }}</dd>
                    </div>
                    <div>
                        <dt class="font-mono text-[11px] uppercase tracking-[0.12em] text-on-surface-variant">Due</dt>
                        <dd class="mt-1 text-sm {{ $invoice->status === 'past_due' ? 'font-medium text-error' : 'text-on-surface' }}">{{ $invoice->due_at?->format('M j, Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-mono text-[11px] uppercase tracking-[0.12em] text-on-surface-variant">Paid</dt>
                        <dd class="mt-1 text-sm text-on-surface">{{ $invoice->paid_at?->format('M j, Y') ?? '—' }}</dd>
                    </div>
                </dl>
            </x-card>

            {{-- Transactions against this invoice --}}
            <x-card :padding="false">
                <div class="p-6 pb-3">
                    <h2 class="font-display text-lg font-semibold text-on-surface">Transactions</h2>
                </div>
                @if ($invoice->transactions->isEmpty())
                    <p class="px-6 pb-6 text-sm text-on-surface-variant">No payment attempts yet.</p>
                @else
                    <div class="scroll-thin overflow-x-auto">
                        <table class="w-full min-w-[540px] text-left">
                            <thead class="bg-surface-ice/60">
                                <tr>
                                    <th class="th">Reference</th>
                                    <th class="th">Method</th>
                                    <th class="th">Amount</th>
                                    <th class="th">Status</th>
                                    <th class="th">When</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($invoice->transactions as $transaction)
                                    <tr class="row">
                                        <td class="td font-mono text-xs text-primary">{{ $transaction->reference }}</td>
                                        <td class="td text-sm text-on-surface-variant">
                                            {{ $transaction->method_brand ? $transaction->method_brand.' •••• '.$transaction->method_last4 : str_replace('_', ' ', ucfirst($transaction->method_type)) }}
                                        </td>
                                        <td class="td font-mono text-sm text-on-surface">{{ $transaction->currency }} {{ number_format((float) $transaction->amount, 2) }}</td>
                                        <td class="td">
                                            <x-badge :variant="['success' => 'success', 'pending' => 'warning', 'rejected' => 'danger', 'failed' => 'danger', 'refunded' => 'neutral'][$transaction->status] ?? 'neutral'">
                                                {{ $transaction->status }}
                                            </x-badge>
                                        </td>
                                        <td class="td font-mono text-xs text-outline">{{ $transaction->created_at?->format('M j, Y · H:i') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        {{-- Record a manual payment --}}
        @can('billing.manage')
            @if (! in_array($invoice->status, ['paid', 'void'], true))
                <div>
                    <x-card x-data="{ type: @js(old('method_type', 'bank_transfer')) }">
                        <h2 class="font-display text-lg font-semibold text-on-surface">Record a payment</h2>
                        <p class="mt-1 text-sm text-on-surface-variant">For offline payments — marks the invoice paid.</p>

                        <form method="POST" action="{{ route('admin.billing.invoices.payments.store', $invoice) }}" class="mt-5 space-y-4">
                            @csrf
                            <x-form.select label="Method" name="method_type" required x-model="type">
                                @foreach (['bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'card' => 'Card', 'wallet' => 'Wallet', 'other' => 'Other'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('method_type', 'bank_transfer') === $value)>{{ $label }}</option>
                                @endforeach
                            </x-form.select>

                            <div x-show="type === 'card'" x-cloak class="grid gap-4 sm:grid-cols-2">
                                <x-form.input label="Card brand" name="method_brand" placeholder="Visa" />
                                <x-form.input label="Last 4 digits" name="method_last4" maxlength="4" placeholder="4242" />
                            </div>

                            <x-form.input type="number" label="Amount" name="amount" :value="$invoice->amount" required step="0.01" min="0.01" />

                            <x-btn class="w-full">
                                <x-icon name="banknotes" class="size-4" /> Record payment
                            </x-btn>
                        </form>
                    </x-card>
                </div>
            @endif
        @endcan
    </div>
</x-admin.layout>
