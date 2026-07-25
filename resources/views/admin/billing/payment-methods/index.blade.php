<x-admin.layout title="Payment methods">
    {{-- Compact header --}}
    <div class="mb-5 flex flex-wrap items-start justify-between gap-x-4 gap-y-3 sm:flex-nowrap">
        <div class="min-w-0">
            <p class="eyebrow mb-1">Finance</p>
            <h1 class="font-display text-2xl font-bold leading-8 tracking-[-0.02em] text-on-surface">Payment methods</h1>
            <p class="mt-0.5 text-[13px] leading-5 text-on-surface-variant">Accounts students pay into — JazzCash, EasyPaisa, bank and more. Attach methods to courses; a method with no courses is available for every course.</p>
        </div>
        <div class="flex shrink-0 items-center gap-2 pt-1.5">
            <x-btn variant="ghost" size="sm" :href="route('admin.billing.plans.index')">Fee plans</x-btn>
            @can('billing.manage')
                <x-btn size="sm" :href="route('admin.billing.payment-methods.create')">
                    <x-icon name="plus" class="size-4" /> Add method
                </x-btn>
            @endcan
        </div>
    </div>

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
                        <p class="font-mono text-xs text-on-surface" style="white-space: nowrap;">{{ $method->account_number }}</p>
                        <p class="text-xs text-on-surface-variant">{{ $method->account_title }}</p>
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
    </x-table>
</x-admin.layout>
