<x-admin.layout title="My leave">
    <x-page-header eyebrow="Team" title="My leave"
        :description="$balance['used'].' of '.$balance['allowance'].' day(s) used in '.$balance['month_label'].'. Pending days are reserved while they wait.'"
        :crumbs="['Dashboard' => route('admin.dashboard'), 'My leave' => null]" />

    <x-form.errors-summary />

    <div class="grid gap-5 lg:grid-cols-[360px_minmax(0,1fr)]">
        <x-card>
            <h2 class="font-display text-[15px] font-semibold text-on-surface">Apply</h2>
            <form method="POST" action="{{ route('admin.team-leave.store') }}" class="mt-4 space-y-4">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-form.input type="date" label="From" name="from_date" required />
                    <x-form.input type="date" label="To" name="to_date" required />
                </div>
                <x-form.textarea label="Reason" name="reason" rows="3" required />
                <x-btn class="w-full"><x-icon name="check" class="size-4" /> Apply</x-btn>
            </form>
            <p class="mt-3 text-[11px] leading-4 text-outline">
                Weekends and holidays inside a range are not leave from anything — they never become days and spend no allowance.
                An admin reviews each day separately.
            </p>
        </x-card>

        <div class="space-y-3">
            @forelse ($applications as $leave)
                <x-card>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-sm text-on-surface">
                                {{ $leave->from_date->format('j M Y') }} – {{ $leave->to_date->format('j M Y') }}
                            </p>
                            <p class="mt-1 text-[13px] leading-5 text-on-surface-variant">{{ $leave->reason }}</p>
                        </div>
                        <x-badge :variant="match ($leave->status) {
                            'approved' => 'success',
                            'partially_approved' => 'warning',
                            'rejected' => 'danger',
                            default => 'neutral',
                        }">{{ str($leave->status)->replace('_', ' ')->title() }}</x-badge>
                    </div>

                    @if ($leave->decisions->isNotEmpty())
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($leave->decisions as $decision)
                                <span class="rounded-full px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-[0.06em] {{ match ($decision->status) {
                                    'approved' => 'bg-success-container text-success',
                                    'declined' => 'bg-error-container text-error',
                                    default => 'bg-on-surface-variant/10 text-on-surface-variant',
                                } }}">{{ $decision->date->format('j M') }}</span>
                            @endforeach
                        </div>
                    @endif

                    @if ($leave->review_note)
                        {{-- The reason reaches the member. Somebody told "no" is
                             owed it, which is why it is required on any decline. --}}
                        <div class="mt-3 rounded-xl bg-surface-ice/70 px-4 py-3">
                            <p class="text-[11px] font-medium uppercase tracking-[0.08em] text-outline">Reviewer's note</p>
                            <p class="mt-1 text-[13px] leading-5 text-on-surface">{{ $leave->review_note }}</p>
                            <p class="mt-1 font-mono text-[11px] text-outline">— {{ $leave->reviewer?->name }}</p>
                        </div>
                    @endif
                </x-card>
            @empty
                <x-card><x-empty-state icon="calendar" title="No applications yet" description="Leave you apply for shows up here with the decision on each day." /></x-card>
            @endforelse
        </div>
    </div>
</x-admin.layout>
