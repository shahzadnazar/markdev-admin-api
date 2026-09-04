<x-admin.layout title="Leave requests">
    @php
        $statusMeta = [
            'pending' => ['label' => 'Pending', 'badge' => 'warning'],
            'approved' => ['label' => 'Approved', 'badge' => 'success'],
            'partially_approved' => ['label' => 'Part approved', 'badge' => 'warning'],
            'rejected' => ['label' => 'Declined', 'badge' => 'danger'],
        ];
    @endphp

    <x-page-header title="Leave requests"
        description="Student leave applications. Approve the days you accept and decline the rest; approved days become leave in the register when that day closes."
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Leave requests' => null]">
        <x-slot:actions>
            <x-btn variant="ghost" size="sm" :href="route('admin.attendance.daily')">
                <x-icon name="check" class="size-4" /> Daily register
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    {{-- Status tabs --}}
    <div class="mb-6 inline-flex rounded-lg bg-white p-1 shadow-card">
        @foreach (['pending' => 'Pending', 'approved' => 'Approved', 'partially_approved' => 'Part approved', 'rejected' => 'Declined', 'all' => 'All'] as $key => $label)
            <a href="{{ route('admin.leaves.index', ['status' => $key]) }}"
                class="inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition {{ $status === $key ? 'bg-primary/10 text-primary' : 'text-on-surface-variant hover:text-on-surface' }}">
                {{ $label }}
                @if ($key === 'pending' && $pendingCount > 0)
                    <span class="rounded-full bg-warning-container px-2 py-0.5 font-mono text-[11px] font-semibold text-warning">{{ $pendingCount }}</span>
                @endif
            </a>
        @endforeach
    </div>

    <x-form.errors-summary />

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Student</th>
                <th class="th">Dates</th>
                <th class="th">Reason</th>
                <th class="th">Status</th>
                <th class="th">Reviewed</th>
                <th class="th">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($leaves as $leave)
                @php $daysCount = (int) $leave->from_date->diffInDays($leave->to_date) + 1; @endphp
                <tr class="row">
                    <td class="td">
                        @if ($leave->user)
                            @can('students.view')
                                <a href="{{ route('admin.students.show', $leave->user) }}" class="font-medium text-on-surface hover:text-primary hover:underline">{{ $leave->user->name }}</a>
                            @else
                                <p class="font-medium text-on-surface">{{ $leave->user->name }}</p>
                            @endcan
                            <p class="font-mono text-[11px] text-outline">{{ $leave->user->studentProfile?->reg_no ?? $leave->user->email }}</p>
                        @else
                            <span class="text-sm text-outline">Deleted user</span>
                        @endif
                    </td>
                    <td class="td">
                        <p class="font-medium text-on-surface">
                            {{ $leave->from_date->format('M j, Y') }}
                            @unless ($leave->from_date->isSameDay($leave->to_date))
                                <span class="text-outline">→</span> {{ $leave->to_date->format('M j, Y') }}
                            @endunless
                        </p>
                        <p class="font-mono text-[11px] text-outline">{{ $daysCount }} {{ \Illuminate\Support\Str::plural('day', $daysCount) }} · applied {{ $leave->created_at->diffForHumans() }}</p>
                    </td>
                    <td class="td max-w-[16rem]">
                        <p class="break-words text-sm text-on-surface-variant" title="{{ $leave->reason }}">{{ \Illuminate\Support\Str::limit($leave->reason, 80) }}</p>
                    </td>
                    <td class="td">
                        <x-badge :variant="$statusMeta[$leave->status]['badge'] ?? 'neutral'">{{ $statusMeta[$leave->status]['label'] ?? $leave->status }}</x-badge>
                    </td>
                    <td class="td max-w-[14rem]">
                        @if ($leave->reviewed_at)
                            <p class="text-sm text-on-surface">{{ $leave->reviewer?->name ?? '—' }}</p>
                            <p class="font-mono text-[11px] text-outline">{{ $leave->reviewed_at->format('M j · g:i A') }}</p>
                            @if ($leave->decisions->isNotEmpty())
                                @php
                                    $approvedDays = $leave->decisions->where('status', 'approved');
                                    $declinedDays = $leave->decisions->where('status', 'declined');
                                @endphp
                                <p class="mt-0.5 font-mono text-[11px] text-outline">
                                    {{ $approvedDays->count() }} approved · {{ $declinedDays->count() }} declined
                                </p>
                                @if ($approvedDays->isNotEmpty() && $declinedDays->isNotEmpty())
                                    <p class="font-mono text-[11px] text-outline" title="Approved: {{ $approvedDays->map(fn ($d) => $d->date->format('M j'))->implode(', ') }}">
                                        {{ \Illuminate\Support\Str::limit($approvedDays->map(fn ($d) => $d->date->format('M j'))->implode(', '), 40) }}
                                    </p>
                                @endif
                            @endif
                            @if ($leave->review_note)
                                <p class="mt-0.5 break-words text-xs text-on-surface-variant" title="{{ $leave->review_note }}">{{ \Illuminate\Support\Str::limit($leave->review_note, 60) }}</p>
                            @endif
                        @else
                            <span class="font-mono text-xs text-outline">—</span>
                        @endif
                    </td>
                    <td class="td">
                        @if ($leave->status === 'pending')
                            <x-btn type="button" size="sm" x-data x-on:click="$dispatch('open-modal', 'review-leave-{{ $leave->id }}')">
                                <x-icon name="check" class="size-4" /> Review
                            </x-btn>
                        @else
                            <span class="font-mono text-xs text-outline">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty-state icon="calendar"
                    :title="$status === 'pending' ? 'No leave requests waiting' : 'Nothing here'"
                    :description="$status === 'pending' ? 'When a student applies for leave from the portal, it lands here for review.' : 'Switch tabs to see other applications.'" /></td></tr>
            @endforelse
        </tbody>
        @if ($leaves->hasPages() || $leaves->total() > 0)
            <x-slot:footer>
                {{ $leaves->links() }}
            </x-slot:footer>
        @endif
    </x-table>

    {{-- Review modals (outside the table so the markup stays valid) --}}
    @foreach ($leaves as $leave)
        @if ($leave->status === 'pending')
            @php
                $leaveDays = $leave->days();
                $isSingleDay = count($leaveDays) === 1;
            @endphp
            <x-modal :name="'review-leave-'.$leave->id" max-width="lg">
                @if (session('review_leave') === $leave->id)
                    {{-- Reopen the dialog the error came from, so the message
                         is not stranded behind a closed modal. --}}
                    <div x-init="$nextTick(() => $dispatch('open-modal', 'review-leave-{{ $leave->id }}'))"></div>
                @endif
                {{-- The day checkboxes and the note live in one form: what is
                     ticked when it submits is exactly what gets approved, and
                     "Decline all" is the same form with nothing ticked. --}}
                <form method="POST" action="{{ route('admin.leaves.review', $leave) }}" class="space-y-4 p-6"
                    x-data="{
                        days: @js(collect($leaveDays)->map->toDateString()->all()),
                        all: @js(collect($leaveDays)->map->toDateString()->all()),
                        get declining() { return this.days.length < this.all.length },
                    }">
                    @csrf

                    <h3 class="font-display text-lg font-semibold text-on-surface">
                        Review leave — {{ $leave->user?->name }}
                    </h3>
                    <p class="text-sm text-on-surface-variant">{{ $leave->reason }}</p>

                    @if ($isSingleDay)
                        <input type="hidden" name="days[]" value="{{ $leaveDays[0]->toDateString() }}">
                        <p class="rounded-xl bg-surface-ice/60 px-4 py-3 text-sm text-on-surface">
                            {{ $leaveDays[0]->format('l, M j, Y') }} — one day.
                        </p>
                    @else
                        <div>
                            <p class="mb-1.5 text-[13px] font-medium text-on-surface">Days to approve</p>
                            <p class="mb-2 text-xs text-outline">Anything left unticked is declined.</p>
                            <div class="max-h-64 space-y-1 overflow-y-auto rounded-xl border border-surface-ice p-2">
                                @foreach ($leaveDays as $day)
                                    <label class="flex cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-on-surface hover:bg-surface-ice">
                                        <input type="checkbox" name="days[]" value="{{ $day->toDateString() }}"
                                            class="size-4 cursor-pointer rounded" x-model="days">
                                        <span>{{ $day->format('l, M j, Y') }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="mt-1.5 font-mono text-[11px] text-outline">
                                <span x-text="days.length"></span> of {{ count($leaveDays) }} approved
                            </p>
                        </div>
                    @endif

                    {{-- Required whenever anything is turned down, which is the
                         same condition the controller validates on. --}}
                    <x-form.textarea label="Note to the student" name="review_note" rows="3"
                        placeholder="e.g. Too close to the final assessment — please reschedule." />
                    <p class="-mt-3 text-xs" :class="declining ? 'text-error' : 'text-outline'"
                        x-text="declining ? 'Required — a declined day has to say why.' : 'Optional on a full approval.'"></p>

                    <div class="flex justify-between gap-3">
                        <x-btn type="submit" name="decline_all" value="1" variant="danger-ghost">
                            <x-icon name="x-mark" class="size-4" /> Decline all
                        </x-btn>
                        <div class="flex gap-3">
                            <x-btn type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'review-leave-{{ $leave->id }}')">Cancel</x-btn>
                            <x-btn type="submit">
                                <x-icon name="check" class="size-4" />
                                <span x-text="days.length === all.length ? 'Approve all' : 'Approve ' + days.length + ' day(s)'"></span>
                            </x-btn>
                        </div>
                    </div>
                </form>
            </x-modal>
        @endif
    @endforeach
</x-admin.layout>
