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

            <form method="GET" action="{{ route('admin.billing.plans.index') }}" class="ml-auto flex items-center gap-2" data-live-search="#plans-results">
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

    <div id="plans-results" class="transition-opacity duration-150">
        @include('admin.billing.plans._results')
    </div>
</x-admin.layout>
