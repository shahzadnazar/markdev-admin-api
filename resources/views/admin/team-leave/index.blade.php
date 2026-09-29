{{-- ADMIN ONLY. A team-lead never reaches this screen — not for their team and
     not for their own application. See TeamLeaveController for why. --}}
<x-admin.layout title="Team leave">
    <x-page-header eyebrow="Team" title="Team leave"
        description="Each day is decided separately. A decline needs a written reason, and the member is shown it."
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Team leave' => null]" />

    <x-form.errors-summary />

    <div class="space-y-3">
        @forelse ($applications as $leave)
            <x-card>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-on-surface">{{ $leave->user?->name }}</p>
                        <p class="mt-0.5 font-mono text-[12px] text-outline">
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

                @if ($leave->status === 'pending')
                    <form method="POST" action="{{ route('admin.team-leave.review', $leave) }}" class="mt-4 border-t border-surface-ice pt-4">
                        @csrf
                        <p class="text-[11px] font-medium uppercase tracking-[0.08em] text-outline">Tick the days to approve</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($leave->days() as $day)
                                <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-outline-variant/60 px-3 py-1.5">
                                    <input type="checkbox" name="days[]" value="{{ $day->toDateString() }}" class="check" checked>
                                    <span class="font-mono text-[11px] text-on-surface">{{ $day->format('j M') }}</span>
                                </label>
                            @endforeach
                        </div>
                        <x-form.textarea label="Note" name="review_note" rows="2" class="mt-3"
                            hint="Required if any day is declined — the member is shown it." />
                        <div class="mt-3 flex items-center gap-2.5">
                            <x-btn size="sm"><x-icon name="check" class="size-4" /> Record decision</x-btn>
                            <x-btn size="sm" variant="secondary" type="submit" name="decline_all" value="1">Decline all</x-btn>
                        </div>
                    </form>
                @elseif ($leave->review_note)
                    <div class="mt-3 rounded-xl bg-surface-ice/70 px-4 py-3">
                        <p class="text-[13px] leading-5 text-on-surface">{{ $leave->review_note }}</p>
                        <p class="mt-1 font-mono text-[11px] text-outline">— {{ $leave->reviewer?->name }}</p>
                    </div>
                @endif
            </x-card>
        @empty
            <x-card><x-empty-state icon="calendar" title="No applications" /></x-card>
        @endforelse
    </div>
</x-admin.layout>
