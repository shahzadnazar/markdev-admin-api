<x-admin.layout :title="'Plan — '.($plan->user?->name ?? 'student')">
<div x-data="planAdjust()">
    @php
        $statusMeta = [
            'upcoming' => ['label' => 'Upcoming', 'badge' => 'neutral'],
            'open' => ['label' => 'Open', 'badge' => 'primary'],
            'pending' => ['label' => 'Pending review', 'badge' => 'warning'],
            'paid' => ['label' => 'Paid', 'badge' => 'success'],
            'past_due' => ['label' => 'Past due', 'badge' => 'danger'],
            'void' => ['label' => 'Void', 'badge' => 'neutral'],
        ];
        $planDescription = $plan->title.' — Rs '.number_format((float) $plan->total_amount).' over '.($plan->installment_months ?? $invoices->count()).' months, '
            .'due day '.$plan->due_day.', fine Rs '.number_format((float) ($plan->fine_per_day ?? \App\Support\BillingConfig::finePerDay())).'/day after '.$graceDays.' grace day(s).';
    @endphp

    <x-page-header :title="$plan->user?->name ?? 'Deleted user'" :description="$planDescription"
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Fee plans' => route('admin.billing.plans.index'), ($plan->user?->name ?? 'Deleted user') => null]">
        <x-slot:meta>
            @if ($plan->user?->studentProfile?->reg_no)
                <x-badge variant="primary">{{ $plan->user->studentProfile->reg_no }}</x-badge>
            @endif
        </x-slot:meta>
        <x-slot:actions>
            <x-btn variant="ghost" size="sm" :href="route('admin.billing.plans.index')">
                <x-icon name="arrow-left" class="size-4" /> Fee plans
            </x-btn>
            @can('students.view')
                @if ($plan->user)
                    <x-btn variant="ghost" size="sm" :href="route('admin.students.show', $plan->user)">
                        <x-icon name="user-circle" class="size-4" /> Profile
                    </x-btn>
                @endif
            @endcan
            @can('billing.manage')
                <x-btn variant="secondary" size="sm" :href="route('admin.billing.plans.edit', $plan)">
                    <x-icon name="pencil" class="size-4" /> Edit plan
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Plan summary strip --}}
    <x-card :padding="false" class="mb-4">
        <div class="flex flex-wrap items-center gap-x-5 gap-y-1.5 px-4 py-2.5">
            <span class="inline-flex items-center gap-2">
                <div class="h-1.5 w-24 overflow-hidden rounded-full bg-surface-ice">
                    <div class="h-full rounded-full bg-gradient-to-r from-primary to-secondary"
                        style="width: {{ $summary['total_count'] > 0 ? round($summary['paid_count'] / $summary['total_count'] * 100) : 0 }}%"></div>
                </div>
                <span class="font-mono text-[11px] text-on-surface">{{ $summary['paid_count'] }}/{{ $summary['total_count'] }} paid</span>
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="size-2 shrink-0 rounded-full bg-success"></span>
                <span class="font-display text-sm font-bold leading-none text-on-surface">Rs {{ number_format($summary['collected']) }}</span>
                <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-on-surface-variant">Collected</span>
            </span>
            @if (($summary['due_now'] ?? 0) > 0)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-warning/10 px-2.5 py-1">
                    <span class="size-2 shrink-0 rounded-full bg-warning"></span>
                    <span class="font-display text-sm font-bold leading-none text-on-surface">Rs {{ number_format($summary['due_now']) }}</span>
                    <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-on-surface-variant">Payable now</span>
                </span>
            @endif
            <span class="inline-flex items-center gap-1.5">
                <span class="size-2 shrink-0 rounded-full bg-warning"></span>
                <span class="font-display text-sm font-bold leading-none text-on-surface">Rs {{ number_format($summary['outstanding']) }}</span>
                <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-on-surface-variant">Outstanding</span>
            </span>
            @if ($summary['fines'] > 0)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-error/10 px-2.5 py-1">
                    <span class="size-2 shrink-0 rounded-full bg-error"></span>
                    <span class="font-display text-sm font-bold leading-none text-on-surface">Rs {{ number_format($summary['fines']) }}</span>
                    <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-on-surface-variant">Fines charged</span>
                </span>
            @endif
            @if ($summary['next_due'])
                <span class="inline-flex items-center gap-1.5">
                    <span class="size-2 shrink-0 rounded-full bg-primary"></span>
                    <span class="font-display text-sm font-bold leading-none text-primary">{{ $summary['next_due']->format('M j, Y') }}</span>
                    <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-on-surface-variant">Next due</span>
                </span>
            @endif
        </div>
    </x-card>

    {{-- Installment schedule --}}
    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">#</th>
                <th class="th">Installment</th>
                <th class="th">Due</th>
                <th class="th">Status</th>
                <th class="th td-num">Amount</th>
                <th class="th td-num">Fine</th>
                <th class="th td-num">Payable</th>
                <th class="th">Paid</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($invoices as $invoice)
                <tr class="row {{ $invoice->status === 'upcoming' ? 'opacity-60' : '' }}">
                    <td class="td font-mono text-xs text-on-surface-variant">
                        @if ($invoice->type === 'registration')
                            <x-badge variant="secondary">REG</x-badge>
                        @else
                            {{ $invoice->sequence_no ?? $loop->iteration }}/{{ $summary['total_count'] }}
                        @endif
                    </td>
                    <td class="td" style="max-width: 18rem;">
                        @php
                            // The page header already names the plan — show only the
                            // installment part so "advance"/"Registration fee" stay visible.
                            $rowLabel = $invoice->type === 'registration'
                                ? 'Registration fee'
                                : trim(\Illuminate\Support\Str::after($invoice->title ?? '', '— '));
                        @endphp
                        <p class="truncate text-sm font-medium text-on-surface" title="{{ $invoice->title }}">{{ \Illuminate\Support\Str::limit($rowLabel ?: $invoice->title, 40, '…') }}</p>
                        <p class="font-mono text-[11px] text-outline">{{ $invoice->number }}</p>
                    </td>
                    <td class="td font-mono text-xs text-on-surface" style="white-space: nowrap;">
                        {{ $invoice->due_at?->format('M j, Y') }}
                        @if (in_array($invoice->status, ['open', 'past_due'], true) && $invoice->due_at?->isToday())
                            <p class="text-[11px] font-semibold text-warning">due today</p>
                        @endif
                        @if ($invoice->status === 'upcoming' && $invoice->activates_at)
                            <p class="text-[11px] text-outline">opens {{ $invoice->activates_at->format('M j') }}</p>
                        @elseif ($invoice->status === 'open' && $invoice->due_at?->isPast() && ! $invoice->due_at->isToday())
                            <p class="text-[11px] text-warning">in grace</p>
                        @elseif ($invoice->status === 'past_due')
                            <p class="text-[11px] text-error">{{ $invoice->daysOverdue() }} day(s) overdue</p>
                        @endif
                    </td>
                    <td class="td">
                        <x-badge :variant="$statusMeta[$invoice->status]['badge'] ?? 'neutral'">{{ $statusMeta[$invoice->status]['label'] ?? $invoice->status }}</x-badge>
                        @if ($invoice->latestSubmission && $invoice->status === 'pending')
                            <p class="mt-1 font-mono text-[10px] text-outline">receipt submitted</p>
                        @endif
                    </td>
                    <td class="td td-num font-mono text-xs text-on-surface" style="white-space: nowrap;">Rs {{ number_format((float) $invoice->amount) }}</td>
                    <td class="td td-num font-mono text-xs {{ (float) $invoice->fine_amount > 0 ? 'text-error' : 'text-outline' }}" style="white-space: nowrap;">
                        {{ (float) $invoice->fine_amount > 0 ? 'Rs '.number_format((float) $invoice->fine_amount) : '—' }}
                    </td>
                    <td class="td td-num font-mono text-xs font-semibold text-on-surface" style="white-space: nowrap;">Rs {{ number_format($invoice->payable_total) }}</td>
                    <td class="td font-mono text-xs text-on-surface-variant" style="white-space: nowrap;">
                        {{ $invoice->paid_at?->format('M j, Y') ?? '—' }}
                    </td>
                    <td class="td text-right">
                        <div class="inline-flex items-center gap-1">
                            @can('billing.manage')
                                @if (in_array($invoice->status, ['upcoming', 'open', 'past_due'], true))
                                    @php
                                        $laterUnpaid = $invoice->type === 'installment'
                                            ? $invoices->where('type', 'installment')
                                                ->whereIn('status', ['upcoming', 'open', 'past_due'])
                                                ->where('sequence_no', '>', $invoice->sequence_no)->count()
                                            : 0;
                                        $adjustPayload = [
                                            'id' => $invoice->id,
                                            'number' => $invoice->number,
                                            'title' => $invoice->type === 'registration' ? 'Registration fee' : 'Installment '.$invoice->sequence_no,
                                            'amount' => (float) $invoice->amount,
                                            'installment' => $invoice->type === 'installment',
                                            'laterUnpaid' => $laterUnpaid,
                                        ];
                                    @endphp
                                    <button type="button" title="Adjust amount" x-on:click='openAdjust(@json($adjustPayload))'
                                        class="cursor-pointer rounded-lg p-2 text-on-surface-variant transition hover:bg-surface-ice hover:text-primary">
                                        <x-icon name="pencil" class="size-4" />
                                    </button>
                                @endif
                            @endcan
                            <x-btn variant="ghost" size="sm" :href="route('admin.billing.invoices.show', $invoice)" title="Open invoice">
                                <x-icon name="eye" class="size-4" />
                            </x-btn>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9"><x-empty-state icon="banknotes" title="No installments"
                    description="This plan has no generated schedule yet." /></td></tr>
            @endforelse
        </tbody>
    </x-table>
    {{-- ···························· Adjust amount popup ···························· --}}
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" x-on:keydown.escape.window="open = false">
            <div x-show="open" x-transition.opacity class="absolute inset-0 bg-primary-deep/20 backdrop-blur-[2px]" x-on:click="open = false"></div>

            <div x-show="open" x-transition class="relative w-full max-w-sm overflow-hidden rounded-2xl bg-white shadow-elevated">
                <div class="border-b border-surface-ice px-5 py-3.5">
                    <p class="font-display text-[15px] font-semibold text-on-surface">Adjust amount</p>
                    <p class="font-mono text-[11px] text-outline"><span x-text="invoice.title"></span> · <span x-text="invoice.number"></span></p>
                </div>
                <form method="POST" :action="'{{ url('admin/billing/invoices') }}/' + invoice.id + '/adjust'" class="space-y-4 px-5 py-4">
                    @csrf
                    <div>
                        <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="adjust-amount">New amount (Rs)</label>
                        <input type="number" name="amount" id="adjust-amount" min="0.01" step="0.01" class="field w-full text-sm" x-model="amount" required>
                        <p class="mt-1 text-[11px] text-outline">Current: Rs <span x-text="invoice.amount ? invoice.amount.toLocaleString() : ''"></span></p>
                    </div>
                    <label x-show="invoice.installment && invoice.laterUnpaid > 0" class="flex cursor-pointer items-start gap-3">
                        <input type="hidden" name="rebalance" value="0">
                        <input type="checkbox" name="rebalance" value="1" class="check mt-0.5" checked>
                        <span>
                            <span class="block text-sm font-medium text-on-surface">Spread the difference</span>
                            <span class="block text-xs text-on-surface-variant">Adds/removes the difference equally across the <span x-text="invoice.laterUnpaid"></span> later unpaid installment(s), so the plan total stays the same.</span>
                        </span>
                    </label>
                    <div class="flex justify-end gap-2.5 pb-1">
                        <x-btn type="button" variant="ghost" size="sm" x-on:click="open = false">Cancel</x-btn>
                        <x-btn type="submit" size="sm"><x-icon name="check" class="size-4" /> Save amount</x-btn>
                    </div>
                </form>
            </div>
        </div>
    </template>
</div>

<script>
    function planAdjust() {
        return {
            open: false,
            invoice: {},
            amount: '',
            openAdjust(invoice) {
                this.invoice = invoice;
                this.amount = invoice.amount;
                this.open = true;
            },
        };
    }
</script>
</x-admin.layout>
