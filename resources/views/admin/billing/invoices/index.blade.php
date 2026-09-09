<x-admin.layout title="Invoices">
    <x-page-header eyebrow="Finance" title="Invoices" description="Everything issued, paid, overdue or voided.">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.billing.plans.index')">Fee plans</x-btn>
            <x-btn variant="ghost" :href="route('admin.billing.transactions.index')">Transactions</x-btn>
            @can('billing.manage')
                <x-btn :href="route('admin.billing.invoices.create')">
                    <x-icon name="plus" class="size-4" /> New invoice
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar results="invoices-results" :action="route('admin.billing.invoices.index')">
        <div class="w-full sm:w-64">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Number or student…" class="field">
        </div>
        <div class="w-44">
            <x-form.label for="status" value="Status" />
            <select name="status" id="status" class="field">
                <option value="">All statuses</option>
                @foreach (['upcoming', 'open', 'pending', 'paid', 'past_due', 'void'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>
                @endforeach
            </select>
        </div>
    </x-filter-bar>

    <div id="invoices-results" class="transition-opacity duration-150">
        @include('admin.billing.invoices._results')
    </div>

</x-admin.layout>
