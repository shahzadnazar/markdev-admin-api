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

        <form method="GET" action="{{ route('admin.billing.submissions') }}" class="flex items-center gap-2">
            <input type="hidden" name="status" value="{{ $status }}">
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Reference or student…" class="field w-64">
            <x-btn variant="secondary" size="md">Search</x-btn>
        </form>
    </div>

    <div class="space-y-4">
        @forelse ($submissions as $submission)
            <x-card :padding="false">
                <div class="flex flex-wrap items-start gap-5 p-6">
                    {{-- Receipt thumbnail --}}
                    @php
                        $receiptUrl = $submission->receipt_url;
                        $isImage = $receiptUrl && ! str_ends_with(strtolower($submission->receipt_path ?? ''), '.pdf');
                    @endphp
                    <button type="button" x-data x-on:click="$dispatch('open-modal', 'receipt-{{ $submission->id }}')"
                        class="group relative size-24 shrink-0 overflow-hidden rounded-xl border border-outline-variant/60 bg-surface-ice"
                        aria-label="View receipt">
                        @if ($isImage)
                            <img src="{{ $receiptUrl }}" alt="Receipt" class="size-full object-cover transition group-hover:scale-105">
                        @else
                            <span class="flex size-full items-center justify-center"><x-icon name="document" class="size-8 text-outline" /></span>
                        @endif
                        <span class="absolute inset-0 flex items-center justify-center bg-primary-deep/0 transition group-hover:bg-primary-deep/30">
                            <x-icon name="eye" class="size-5 text-white opacity-0 transition group-hover:opacity-100" />
                        </span>
                    </button>

                    {{-- Details --}}
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-sm font-semibold text-primary">{{ $submission->reference }}</span>
                            <x-badge :variant="['pending' => 'warning', 'success' => 'success', 'rejected' => 'danger'][$submission->status] ?? 'neutral'">
                                {{ $submission->status === 'success' ? 'approved' : $submission->status }}
                            </x-badge>
                            @if ($submission->invoice)
                                <a href="{{ route('admin.billing.invoices.show', $submission->invoice) }}"
                                    class="font-mono text-xs text-on-surface-variant hover:text-primary hover:underline">{{ $submission->invoice->number }}</a>
                            @endif
                        </div>

                        <dl class="mt-3 grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <dt class="font-mono text-[10px] uppercase tracking-[0.1em] text-outline">Student</dt>
                                <dd class="mt-0.5 font-medium text-on-surface">{{ $submission->user?->name ?? 'Deleted user' }}</dd>
                            </div>
                            <div>
                                <dt class="font-mono text-[10px] uppercase tracking-[0.1em] text-outline">Amount</dt>
                                <dd class="mt-0.5 font-mono font-semibold text-on-surface">{{ $submission->currency }} {{ number_format((float) $submission->amount, 2) }}</dd>
                            </div>
                            <div>
                                <dt class="font-mono text-[10px] uppercase tracking-[0.1em] text-outline">Channel</dt>
                                <dd class="mt-0.5 text-on-surface-variant">{{ $submission->bank_name ?? $submission->method_brand ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="font-mono text-[10px] uppercase tracking-[0.1em] text-outline">Payment ref</dt>
                                <dd class="mt-0.5 font-mono text-xs text-on-surface-variant">{{ $submission->reference_no ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="font-mono text-[10px] uppercase tracking-[0.1em] text-outline">Payer</dt>
                                <dd class="mt-0.5 text-on-surface-variant">{{ $submission->payer_name ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="font-mono text-[10px] uppercase tracking-[0.1em] text-outline">Paid on</dt>
                                <dd class="mt-0.5 text-on-surface-variant">{{ $submission->payment_date?->format('M j, Y') ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="font-mono text-[10px] uppercase tracking-[0.1em] text-outline">Submitted</dt>
                                <dd class="mt-0.5 text-on-surface-variant">{{ $submission->created_at->diffForHumans() }}</dd>
                            </div>
                            @if ($submission->reviewed_at)
                                <div>
                                    <dt class="font-mono text-[10px] uppercase tracking-[0.1em] text-outline">Reviewed</dt>
                                    <dd class="mt-0.5 text-on-surface-variant">{{ $submission->reviewer?->name }} · {{ $submission->reviewed_at->format('M j · H:i') }}</dd>
                                </div>
                            @endif
                        </dl>

                        @if ($submission->notes)
                            <p class="mt-3 rounded-lg bg-surface-ice/70 px-3 py-2 text-xs leading-5 text-on-surface-variant">
                                <span class="font-medium text-on-surface">Student note:</span> {{ $submission->notes }}
                            </p>
                        @endif

                        @if ($submission->status === 'rejected' && $submission->rejection_reason)
                            <p class="mt-3 rounded-lg bg-error-container/50 px-3 py-2 text-xs leading-5 text-on-error-container">
                                <span class="font-medium">Rejection reason:</span> {{ $submission->rejection_reason }}
                            </p>
                        @endif
                    </div>

                    {{-- Actions --}}
                    @if ($submission->status === 'pending')
                        @can('billing.manage')
                            <div class="flex shrink-0 flex-col gap-2">
                                <x-confirm-form
                                    :action="route('admin.billing.submissions.approve', $submission)"
                                    title="Approve this payment?"
                                    message="The invoice will be marked paid and the student notified."
                                    confirm-label="Approve payment"
                                    variant="success"
                                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-success px-4 py-2.5 text-sm font-medium text-white shadow-card transition hover:-translate-y-px hover:opacity-90"
                                >
                                    <x-icon name="check" class="size-4" /> Approve
                                </x-confirm-form>
                                <x-btn type="button" variant="danger-ghost" x-data x-on:click="$dispatch('open-modal', 'reject-{{ $submission->id }}')">
                                    <x-icon name="x-mark" class="size-4" /> Reject
                                </x-btn>
                            </div>
                        @endcan
                    @endif
                </div>
            </x-card>

            {{-- Receipt modal --}}
            <x-modal :name="'receipt-'.$submission->id" max-width="3xl">
                <div class="p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-display text-lg font-semibold text-on-surface">Receipt — {{ $submission->reference }}</h3>
                        <a href="{{ $receiptUrl }}" target="_blank" rel="noreferrer" class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline">
                            <x-icon name="external" class="size-4" /> Open original
                        </a>
                    </div>
                    @if ($isImage)
                        <img src="{{ $receiptUrl }}" alt="Payment receipt" class="max-h-[70vh] w-full rounded-xl object-contain bg-surface-ice">
                    @else
                        <div class="flex flex-col items-center gap-3 rounded-xl bg-surface-ice py-16">
                            <x-icon name="document" class="size-12 text-outline" />
                            <p class="text-sm text-on-surface-variant">PDF receipt — use “Open original” to view it.</p>
                        </div>
                    @endif
                </div>
            </x-modal>

            {{-- Reject modal --}}
            @can('billing.manage')
                <x-modal :name="'reject-'.$submission->id" max-width="md">
                    <form method="POST" action="{{ route('admin.billing.submissions.reject', $submission) }}" class="space-y-4 p-6">
                        @csrf
                        <h3 class="font-display text-lg font-semibold text-on-surface">Reject {{ $submission->reference }}</h3>
                        <p class="text-sm text-on-surface-variant">The reason is sent to the student so they can fix and resubmit.</p>
                        <x-form.textarea label="Reason for rejection" name="rejection_reason" rows="4" required
                            placeholder="e.g. The receipt amount doesn't match the invoice — please upload the full payment slip." />
                        <div class="flex justify-end gap-3">
                            <x-btn type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'reject-{{ $submission->id }}')">Cancel</x-btn>
                            <x-btn variant="danger"><x-icon name="x-mark" class="size-4" /> Reject payment</x-btn>
                        </div>
                    </form>
                </x-modal>
            @endcan
        @empty
            <x-card>
                <x-empty-state icon="banknotes"
                    :title="$status === 'pending' ? 'No submissions waiting' : 'Nothing here'"
                    :description="$status === 'pending' ? 'When a student uploads proof of payment, it lands here for review.' : 'Switch tabs to see other submissions.'" />
            </x-card>
        @endforelse

        @if ($submissions->hasPages())
            <div class="rounded-2xl bg-white px-4 py-3 shadow-card">{{ $submissions->links() }}</div>
        @endif
    </div>
</x-admin.layout>
