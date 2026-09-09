<x-admin.layout title="Transactions">
    <x-page-header eyebrow="Finance" title="Transactions" description="Every payment attempt across the platform.">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.billing.plans.index')">Fee plans</x-btn>
            <x-btn variant="ghost" :href="route('admin.billing.invoices.index')">Invoices</x-btn>
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar results="transactions-results" :action="route('admin.billing.transactions.index')">
        <div class="w-full sm:w-64">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Reference or student…" class="field">
        </div>
        <div class="w-44">
            <x-form.label for="status" value="Status" />
            <select name="status" id="status" class="field">
                <option value="">All statuses</option>
                @foreach (['pending', 'success', 'rejected', 'failed', 'refunded'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
    </x-filter-bar>

    <div id="transactions-results" class="transition-opacity duration-150">
        @include('admin.billing.transactions._results')
    </div>

</x-admin.layout>
