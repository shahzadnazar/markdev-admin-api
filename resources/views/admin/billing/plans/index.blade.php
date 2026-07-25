<x-admin.layout title="Fee plans">
    <x-page-header title="Fee plans"
        description="Every installment plan — progress, outstanding balance and defaulters at a glance."
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Finance' => null, 'Fee plans' => null]">
        <x-slot:actions>
            <x-btn variant="ghost" size="sm" :href="route('admin.billing.invoices.index')">Invoices</x-btn>
            <x-btn variant="ghost" size="sm" :href="route('admin.billing.transactions.index')">Transactions</x-btn>
            @can('billing.manage')
                <x-btn size="sm" :href="route('admin.billing.plans.create')">
                    <x-icon name="plus" class="size-4" /> New fee plan
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Tabs + search + finance strip in one card --}}
    <x-card :padding="false" class="mb-4">
        <div class="flex flex-wrap items-center gap-2 border-b border-surface-ice px-4 py-2.5">
            @foreach (['all' => 'All plans', 'defaulters' => 'Defaulters', 'completed' => 'Completed'] as $key => $label)
                <a href="{{ route('admin.billing.plans.index', array_filter(['tab' => $key === 'all' ? null : $key, 'search' => request('search')])) }}"
                    class="cursor-pointer rounded-lg border px-3 py-1.5 text-[13px] font-medium transition {{ $tab === $key
                        ? 'border-primary bg-primary text-white shadow-card'
                        : 'border-outline/30 bg-white text-on-surface-variant hover:border-primary/50 hover:text-primary' }}">
                    {{ $label }}{{ $key === 'defaulters' && $stats['defaulters'] > 0 ? ' ('.$stats['defaulters'].')' : '' }}
                </a>
            @endforeach

            <form method="GET" action="{{ route('admin.billing.plans.index') }}" class="ml-auto flex items-center gap-2">
                @if ($tab !== 'all')
                    <input type="hidden" name="tab" value="{{ $tab }}">
                @endif
                <label class="sr-only" for="search">Search</label>
                <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Student or plan…" class="field h-9 w-52 text-sm">
                <x-btn variant="secondary" size="sm" class="h-9"><x-icon name="search" class="size-3.5" /> Search</x-btn>
            </form>
        </div>
        <div class="flex flex-wrap items-center gap-x-5 gap-y-1.5 px-4 py-2.5">
            <span class="inline-flex items-center gap-1.5">
                <span class="size-2 shrink-0 rounded-full bg-primary"></span>
                <span class="font-display text-sm font-bold leading-none text-on-surface">{{ number_format($stats['plans']) }}</span>
                <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-on-surface-variant">Plans</span>
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="size-2 shrink-0 rounded-full bg-success"></span>
                <span class="font-display text-sm font-bold leading-none text-on-surface">{{ number_format($stats['active']) }}</span>
                <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-on-surface-variant">Active</span>
            </span>
            <span class="inline-flex items-center gap-1.5 {{ $stats['defaulters'] > 0 ? 'rounded-full bg-error/10 px-2.5 py-1' : '' }}">
                <span class="size-2 shrink-0 rounded-full bg-error"></span>
                <span class="font-display text-sm font-bold leading-none text-on-surface">{{ number_format($stats['defaulters']) }}</span>
                <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-on-surface-variant">Defaulters</span>
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="size-2 shrink-0 rounded-full bg-warning"></span>
                <span class="font-display text-sm font-bold leading-none text-on-surface">Rs {{ number_format($stats['outstanding']) }}</span>
                <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-on-surface-variant">Outstanding</span>
            </span>
        </div>
    </x-card>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Student</th>
                <th class="th">Plan</th>
                <th class="th">Terms</th>
                <th class="th">Progress</th>
                <th class="th td-num">Outstanding</th>
                <th class="th">State</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($plans as $plan)
                @php
                    $rows = $plan->invoices;
                    $installmentRows = $rows->where('type', 'installment');
                    $paidCount = $installmentRows->where('status', 'paid')->count();
                    $total = $installmentRows->count();
                    $outstanding = $rows->whereIn('status', ['open', 'pending', 'past_due'])
                        ->sum(fn ($invoice) => (float) $invoice->amount + (float) $invoice->fine_amount);
                    $hasDefault = $rows->contains(fn ($invoice) => $invoice->status === 'past_due');
                    $hasGrace = $rows->contains(fn ($invoice) => $invoice->status === 'open' && $invoice->due_at?->isPast());
                    [$state, $stateBadge] = match (true) {
                        $total === 0 => ['no schedule', 'neutral'],
                        $paidCount === $total => ['completed', 'success'],
                        $hasDefault => ['defaulter', 'danger'],
                        $hasGrace => ['in grace', 'warning'],
                        ! $plan->is_active => ['inactive', 'neutral'],
                        default => ['on track', 'primary'],
                    };
                @endphp
                <tr class="row">
                    <td class="td">
                        <a href="{{ route('admin.billing.plans.show', $plan) }}" class="block font-medium text-on-surface hover:text-primary">{{ $plan->user?->name ?? 'Deleted user' }}</a>
                        <p class="font-mono text-[11px] text-outline">{{ $plan->user?->studentProfile?->reg_no ?? $plan->user?->email }}</p>
                    </td>
                    <td class="td" style="max-width: 13rem;">
                        <p class="truncate text-sm text-on-surface" title="{{ $plan->title }}">{{ \Illuminate\Support\Str::limit($plan->title, 26, '…') }}</p>
                        <p class="font-mono text-[11px] text-outline">Rs {{ number_format((float) $plan->total_amount) }} total</p>
                    </td>
                    <td class="td font-mono text-xs text-on-surface-variant" style="white-space: nowrap;">
                        {{ $plan->installment_months ?? $total }} mo · day {{ $plan->due_day }}
                        <p class="text-[11px] text-outline">fine Rs {{ number_format((float) ($plan->fine_per_day ?? \App\Support\BillingConfig::finePerDay())) }}/day</p>
                    </td>
                    <td class="td" style="min-width: 8rem;">
                        <div class="flex items-center gap-2">
                            <div class="h-1.5 w-16 overflow-hidden rounded-full bg-surface-ice">
                                <div class="h-full rounded-full {{ $hasDefault ? 'bg-error' : 'bg-gradient-to-r from-primary to-secondary' }}"
                                    style="width: {{ $total > 0 ? round($paidCount / $total * 100) : 0 }}%"></div>
                            </div>
                            <span class="font-mono text-[11px] text-on-surface">{{ $paidCount }}/{{ $total }}</span>
                        </div>
                    </td>
                    <td class="td td-num font-mono text-xs {{ $outstanding > 0 ? 'text-on-surface' : 'text-outline' }}" style="white-space: nowrap;">
                        Rs {{ number_format($outstanding) }}
                    </td>
                    <td class="td"><x-badge :variant="$stateBadge">{{ $state }}</x-badge></td>
                    <td class="td text-right">
                        <div class="inline-flex items-center gap-1">
                            <x-btn variant="ghost" size="sm" :href="route('admin.billing.plans.show', $plan)" title="Installment schedule">
                                <x-icon name="eye" class="size-4" />
                            </x-btn>
                            @can('billing.manage')
                                <x-btn variant="ghost" size="sm" :href="route('admin.billing.plans.edit', $plan)" title="Edit plan">
                                    <x-icon name="pencil" class="size-4" />
                                </x-btn>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty-state icon="banknotes" title="No fee plans"
                    description="Plans are created when you enroll a student with installments, or manually here." /></td></tr>
            @endforelse
        </tbody>
        @if ($plans->hasPages() || $plans->total() > 0)
            <x-slot:footer>
                {{ $plans->links() }}
            </x-slot:footer>
        @endif
    </x-table>
</x-admin.layout>
