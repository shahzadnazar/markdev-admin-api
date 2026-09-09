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
                    ->sum(fn ($invoice) => $invoice->payable_total);
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
            <div data-pagination>{{ $plans->links() }}</div>
        </x-slot:footer>
    @endif
</x-table>
