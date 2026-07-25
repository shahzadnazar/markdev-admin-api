<x-admin.layout title="Payment methods">
    <x-page-header title="Payment methods"
        description="Accounts students pay into — JazzCash, EasyPaisa, bank and more. Attach methods to courses; a method with no courses is available for every course."
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Finance' => null, 'Payment methods' => null]">
        <x-slot:actions>
            <x-btn variant="ghost" size="sm" :href="route('admin.billing.plans.index')">Fee plans</x-btn>
            @can('billing.manage')
                <x-btn size="sm" :href="route('admin.billing.payment-methods.create')">
                    <x-icon name="plus" class="size-4" /> Add method
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Method</th>
                <th class="th">Account</th>
                <th class="th">Courses</th>
                <th class="th">Status</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($methods as $method)
                <tr class="row {{ $method->is_active ? '' : 'opacity-60' }}">
                    <td class="td">
                        <p class="font-medium text-on-surface">{{ $method->name }}</p>
                        <p class="font-mono text-[11px] text-outline">{{ $method->channelLabel() }}{{ $method->bank_name ? ' · '.$method->bank_name : '' }}</p>
                    </td>
                    <td class="td">
                        @if ($method->channel === 'cash_deposit')
                            <p class="font-mono text-xs text-outline">cash at counter</p>
                            <p class="text-xs text-on-surface-variant">receipt number required</p>
                        @else
                            <p class="font-mono text-xs text-on-surface" style="white-space: nowrap;">{{ $method->account_number }}</p>
                            <p class="text-xs text-on-surface-variant">{{ $method->account_title }}</p>
                        @endif
                    </td>
                    <td class="td" style="max-width: 16rem;">
                        @if ($method->courses->isEmpty())
                            <span class="font-mono text-xs text-outline">all courses</span>
                        @else
                            <p class="truncate text-sm text-on-surface"
                                title="{{ $method->courses->pluck('title')->implode(', ') }}">
                                {{ \Illuminate\Support\Str::limit($method->courses->first()->title, 24, '…') }}
                            </p>
                            @if ($method->courses->count() > 1)
                                <p class="mt-0.5 font-mono text-[11px] text-primary">+{{ $method->courses->count() - 1 }} more</p>
                            @endif
                        @endif
                    </td>
                    <td class="td">
                        <x-badge :variant="$method->is_active ? 'success' : 'neutral'">{{ $method->is_active ? 'active' : 'inactive' }}</x-badge>
                    </td>
                    <td class="td text-right">
                        @can('billing.manage')
                            <div class="inline-flex items-center gap-1">
                                <x-btn variant="ghost" size="sm" :href="route('admin.billing.payment-methods.edit', $method)" title="Edit method">
                                    <x-icon name="pencil" class="size-4" />
                                </x-btn>
                                <x-confirm-form :action="route('admin.billing.payment-methods.destroy', $method)" method="DELETE"
                                    title="Remove payment method" :message="'Remove '.$method->name.'? Students will no longer see this account.'" confirm-label="Remove"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                    <x-icon name="trash" class="size-4" />
                                </x-confirm-form>
                            </div>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><x-empty-state icon="wallet" title="No payment methods yet"
                    description="Add JazzCash, EasyPaisa, SadaPay or bank accounts so students know where to pay." /></td></tr>
            @endforelse
        </tbody>
        @if ($methods->total() > 0)
            <x-slot:footer>{{ $methods->links() }}</x-slot:footer>
        @endif
    </x-table>
</x-admin.layout>
