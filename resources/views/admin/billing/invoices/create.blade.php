<x-admin.layout title="New invoice">
    <x-page-header
        eyebrow="Finance"
        title="New invoice"
        :description="'Next number: '.$nextNumber.' — issued against a fee plan.'"
    >
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.billing.invoices.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to invoices
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('admin.billing.invoices.store') }}" class="max-w-2xl">
        @csrf

        <x-card class="space-y-5">
            <x-form.select label="Fee plan" name="fee_plan_id" required hint="The invoice bills this plan's student in the plan's currency.">
                <option value="">Choose a fee plan…</option>
                @foreach ($plans as $plan)
                    <option value="{{ $plan->id }}" @selected(old('fee_plan_id') == $plan->id)>
                        {{ $plan->title }} — {{ $plan->user?->name }} ({{ $plan->currency }} {{ number_format((float) $plan->total_amount, 2) }})
                    </option>
                @endforeach
            </x-form.select>

            <x-form.input label="Title" name="title" required hint="e.g. “Tuition installment 2 of 3”." />

            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.input type="number" label="Amount" name="amount" required step="0.01" min="0.01" />
                <x-form.input type="date" label="Due date" name="due_at" required :value="now()->addMonth()->format('Y-m-d')" />
            </div>
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn>
                <x-icon name="check" class="size-4" /> Create invoice
            </x-btn>
            <x-btn variant="ghost" :href="route('admin.billing.invoices.index')">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
