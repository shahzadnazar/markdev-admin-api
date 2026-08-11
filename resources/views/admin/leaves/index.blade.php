<x-admin.layout title="Leave requests">
    @php
        $statusMeta = [
            'pending' => ['label' => 'Pending', 'badge' => 'warning'],
            'approved' => ['label' => 'Approved', 'badge' => 'success'],
            'rejected' => ['label' => 'Rejected', 'badge' => 'danger'],
        ];
    @endphp

    <x-page-header title="Leave requests"
        description="Student leave applications — approving one marks the whole range as leave in the daily register, which counts as attended."
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Leave requests' => null]">
        <x-slot:actions>
            <x-btn variant="ghost" size="sm" :href="route('admin.attendance.daily')">
                <x-icon name="check" class="size-4" /> Daily register
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    {{-- Status tabs --}}
    <div class="mb-6 inline-flex rounded-lg bg-white p-1 shadow-card">
        @foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label)
            <a href="{{ route('admin.leaves.index', ['status' => $key]) }}"
                class="inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition {{ $status === $key ? 'bg-primary/10 text-primary' : 'text-on-surface-variant hover:text-on-surface' }}">
                {{ $label }}
                @if ($key === 'pending' && $pendingCount > 0)
                    <span class="rounded-full bg-warning-container px-2 py-0.5 font-mono text-[11px] font-semibold text-warning">{{ $pendingCount }}</span>
                @endif
            </a>
        @endforeach
    </div>

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
                            @if ($leave->review_note)
                                <p class="mt-0.5 break-words text-xs text-on-surface-variant" title="{{ $leave->review_note }}">{{ \Illuminate\Support\Str::limit($leave->review_note, 60) }}</p>
                            @endif
                        @else
                            <span class="font-mono text-xs text-outline">—</span>
                        @endif
                    </td>
                    <td class="td">
                        @if ($leave->status === 'pending')
                            <div class="flex items-center gap-2">
                                <x-confirm-form
                                    :action="route('admin.leaves.approve', $leave)"
                                    title="Approve this leave?"
                                    :message="'The '.$daysCount.' day(s) will be marked as leave in the daily register (counts as attended) and the student notified.'"
                                    confirm-label="Approve leave"
                                    variant="success"
                                    class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-success px-3 py-1.5 text-xs font-medium text-white shadow-card transition hover:-translate-y-px hover:opacity-90"
                                >
                                    <x-icon name="check" class="size-4" /> Approve
                                </x-confirm-form>
                                <x-btn type="button" variant="danger-ghost" size="sm" x-data x-on:click="$dispatch('open-modal', 'reject-leave-{{ $leave->id }}')">
                                    <x-icon name="x-mark" class="size-4" /> Reject
                                </x-btn>
                            </div>
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

    {{-- Reject modals (outside the table so markup stays valid) --}}
    @foreach ($leaves as $leave)
        @if ($leave->status === 'pending')
            <x-modal :name="'reject-leave-'.$leave->id" max-width="md">
                <form method="POST" action="{{ route('admin.leaves.reject', $leave) }}" class="space-y-4 p-6">
                    @csrf
                    <h3 class="font-display text-lg font-semibold text-on-surface">Reject leave — {{ $leave->user?->name }}</h3>
                    <p class="text-sm text-on-surface-variant">
                        {{ $leave->from_date->format('M j, Y') }}{{ $leave->from_date->isSameDay($leave->to_date) ? '' : ' → '.$leave->to_date->format('M j, Y') }} —
                        the student is notified and those days stay as normal attendance.
                    </p>
                    <x-form.textarea label="Note to the student (optional)" name="review_note" rows="3"
                        placeholder="e.g. Too close to the final assessment — please reschedule." />
                    <div class="flex justify-end gap-3">
                        <x-btn type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'reject-leave-{{ $leave->id }}')">Cancel</x-btn>
                        <x-btn variant="danger"><x-icon name="x-mark" class="size-4" /> Reject leave</x-btn>
                    </div>
                </form>
            </x-modal>
        @endif
    @endforeach
</x-admin.layout>
