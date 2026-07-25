<x-admin.layout :title="'Plan — '.($plan->user?->name ?? 'student')">
    @php
        $statusMeta = [
            'upcoming' => ['label' => 'Upcoming', 'badge' => 'neutral'],
            'open' => ['label' => 'Open', 'badge' => 'primary'],
            'pending' => ['label' => 'Pending review', 'badge' => 'warning'],
            'paid' => ['label' => 'Paid', 'badge' => 'success'],
            'past_due' => ['label' => 'Past due', 'badge' => 'danger'],
            'void' => ['label' => 'Void', 'badge' => 'neutral'],
        ];
    @endphp

    {{-- Compact header --}}
    <div class="mb-5 flex flex-wrap items-start justify-between gap-x-4 gap-y-3 sm:flex-nowrap">
        <div class="min-w-0">
            <p class="eyebrow mb-1">Finance · Fee plan</p>
            <div class="flex flex-wrap items-center gap-x-2.5">
                <h1 class="truncate font-display text-2xl font-bold leading-8 tracking-[-0.02em] text-on-surface">{{ $plan->user?->name ?? 'Deleted user' }}</h1>
                @if ($plan->user?->studentProfile?->reg_no)
                    <x-badge variant="primary">{{ $plan->user->studentProfile->reg_no }}</x-badge>
                @endif
            </div>
            <p class="mt-0.5 text-[13px] leading-5 text-on-surface-variant">
                {{ $plan->title }} — Rs {{ number_format((float) $plan->total_amount) }} over {{ $plan->installment_months ?? $invoices->count() }} months,
                due day {{ $plan->due_day }}, fine Rs {{ number_format((float) ($plan->fine_per_day ?? \App\Support\BillingConfig::finePerDay())) }}/day after {{ $graceDays }} grace day(s).
            </p>
        </div>
        <div class="flex shrink-0 items-center gap-2 pt-1.5">
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
        </div>
    </div>

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
                <th class="th">Amount</th>
                <th class="th">Fine</th>
                <th class="th">Payable</th>
                <th class="th">Paid</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($invoices as $invoice)
                <tr class="row {{ $invoice->status === 'upcoming' ? 'opacity-60' : '' }}">
                    <td class="td font-mono text-xs text-on-surface-variant">{{ $invoice->sequence_no ?? $loop->iteration }}/{{ $summary['total_count'] }}</td>
                    <td class="td" style="max-width: 14rem;">
                        <p class="truncate text-sm font-medium text-on-surface" title="{{ $invoice->title }}">{{ \Illuminate\Support\Str::limit($invoice->title, 30, '…') }}</p>
                        <p class="font-mono text-[11px] text-outline">{{ $invoice->number }}</p>
                    </td>
                    <td class="td font-mono text-xs text-on-surface" style="white-space: nowrap;">
                        {{ $invoice->due_at?->format('M j, Y') }}
                        @if ($invoice->status === 'upcoming' && $invoice->activates_at)
                            <p class="text-[11px] text-outline">opens {{ $invoice->activates_at->format('M j') }}</p>
                        @elseif ($invoice->status === 'open' && $invoice->due_at?->isPast())
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
                    <td class="td font-mono text-xs text-on-surface" style="white-space: nowrap;">Rs {{ number_format((float) $invoice->amount) }}</td>
                    <td class="td font-mono text-xs {{ (float) $invoice->fine_amount > 0 ? 'text-error' : 'text-outline' }}" style="white-space: nowrap;">
                        {{ (float) $invoice->fine_amount > 0 ? 'Rs '.number_format((float) $invoice->fine_amount) : '—' }}
                    </td>
                    <td class="td font-mono text-xs font-semibold text-on-surface" style="white-space: nowrap;">Rs {{ number_format($invoice->payable_total) }}</td>
                    <td class="td font-mono text-xs text-on-surface-variant" style="white-space: nowrap;">
                        {{ $invoice->paid_at?->format('M j, Y') ?? '—' }}
                    </td>
                    <td class="td text-right">
                        <x-btn variant="ghost" size="sm" :href="route('admin.billing.invoices.show', $invoice)" title="Open invoice">
                            <x-icon name="eye" class="size-4" />
                        </x-btn>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9"><x-empty-state icon="banknotes" title="No installments"
                    description="This plan has no generated schedule yet." /></td></tr>
            @endforelse
        </tbody>
    </x-table>
</x-admin.layout>
