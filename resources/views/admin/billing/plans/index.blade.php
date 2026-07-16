<x-admin.layout title="Fee plans">
    <x-page-header eyebrow="Finance" title="Fee plans" description="What each student is billed for.">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.billing.invoices.index')">Invoices</x-btn>
            <x-btn variant="ghost" :href="route('admin.billing.transactions.index')">Transactions</x-btn>
            @can('billing.manage')
                <x-btn :href="route('admin.billing.plans.create')">
                    <x-icon name="plus" class="size-4" /> New fee plan
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar :action="route('admin.billing.plans.index')">
        <div class="w-full sm:w-72">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Student or plan title…" class="field">
        </div>
    </x-filter-bar>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Plan</th>
                <th class="th">Student</th>
                <th class="th">Cycle</th>
                <th class="th">Total</th>
                <th class="th">Status</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($plans as $plan)
                <tr class="row">
                    <td class="td max-w-[18rem]">
                        <p class="truncate font-medium text-on-surface">{{ $plan->title }}</p>
                        @if ($plan->course)
                            <p class="truncate text-xs text-outline">{{ $plan->course->title }}</p>
                        @endif
                    </td>
                    <td class="td">
                        <p class="font-medium text-on-surface">{{ $plan->user?->name ?? 'Deleted user' }}</p>
                        <p class="text-xs text-outline">{{ $plan->user?->email }}</p>
                    </td>
                    <td class="td"><x-badge variant="secondary">{{ str_replace('_', ' ', $plan->billing_cycle) }}</x-badge></td>
                    <td class="td font-mono text-sm text-on-surface">{{ $plan->currency }} {{ number_format((float) $plan->total_amount, 2) }}</td>
                    <td class="td">
                        <x-badge :variant="$plan->is_active ? 'success' : 'neutral'">{{ $plan->is_active ? 'active' : 'inactive' }}</x-badge>
                    </td>
                    <td class="td text-right">
                        @can('billing.manage')
                            <x-btn variant="ghost" size="sm" :href="route('admin.billing.plans.edit', $plan)">
                                <x-icon name="pencil" class="size-4" />
                            </x-btn>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty-state icon="banknotes" title="No fee plans" description="Create a fee plan to start billing a student." /></td></tr>
            @endforelse
        </tbody>
        @if ($plans->hasPages())
            <x-slot:footer>{{ $plans->links() }}</x-slot:footer>
        @endif
    </x-table>
</x-admin.layout>
