<x-admin.layout title="Fee review">
    <x-page-header eyebrow="Finance" title="Fee review"
        description="Student payment submissions — verify the receipt, then approve or reject with a reason.">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.billing.invoices.index')">Invoices</x-btn>
            <x-btn variant="ghost" :href="route('admin.billing.transactions.index')">Transactions</x-btn>
        </x-slot:actions>
    </x-page-header>

    {{-- Status tabs --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="inline-flex rounded-lg bg-white p-1 shadow-card">
            @foreach (['pending' => 'Pending', 'success' => 'Approved', 'rejected' => 'Rejected'] as $key => $label)
                <a href="{{ route('admin.billing.submissions', ['status' => $key]) }}"
                    class="inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition {{ $status === $key ? 'bg-primary/10 text-primary' : 'text-on-surface-variant hover:text-on-surface' }}">
                    {{ $label }}
                    @if ($key === 'pending' && $pendingCount > 0)
                        <span class="rounded-full bg-warning-container px-2 py-0.5 font-mono text-[11px] font-semibold text-warning">{{ $pendingCount }}</span>
                    @endif
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('admin.billing.submissions') }}" class="flex items-center gap-2" data-live-search="#submissions-results">
            <input type="hidden" name="status" value="{{ $status }}">
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Reference or student…" class="field w-64">
            <x-btn variant="secondary" size="md">Search</x-btn>
        </form>
    </div>

    <div id="submissions-results" class="transition-opacity duration-150">
        @include('admin.billing._submissions-results')
    </div>
</x-admin.layout>
