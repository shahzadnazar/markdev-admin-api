<x-table>
    <thead class="bg-surface-ice/60">
        <tr>
            <th class="th">Student</th>
            <th class="th">Course</th>
            <th class="th">Enrolled</th>
            <th class="th">Progress</th>
            {{-- Header AND cells, together. A Fee column with empty cells
                 under it is worse than no column: it tells a viewer there is
                 something here they are not being shown. --}}
            @can('billing.view')
                <th class="th">Fee</th>
            @endcan
            <th class="th">Status</th>
            <th class="th text-right">Actions</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($enrollments as $enrollment)
            <tr class="row">
                <td class="td">
                    <p class="font-medium text-on-surface">{{ $enrollment->user?->name ?? 'Deleted user' }}</p>
                    <p class="text-xs text-outline">{{ $enrollment->user?->email }}</p>
                </td>
                <td class="td max-w-[18rem]">
                    <p class="truncate text-on-surface-variant">{{ $enrollment->course?->title ?? '—' }}</p>
                </td>
                <td class="td font-mono text-xs text-outline">{{ $enrollment->enrolled_at?->format('j M Y') }}</td>
                <td class="td">
                    <div class="flex items-center gap-2.5">
                        <div class="h-1.5 w-24 overflow-hidden rounded-full bg-surface-ice">
                            <div class="h-full rounded-full bg-gradient-to-r from-primary to-secondary" style="width: {{ min(100, (float) $enrollment->progress_percent) }}%"></div>
                        </div>
                        <span class="font-mono text-xs text-on-surface-variant">{{ round((float) $enrollment->progress_percent) }}%</span>
                    </div>
                </td>
                {{-- Gated on billing.view, not on a role name. A fifth role
                     added later inherits the rule instead of silently seeing
                     the money. The controller also skips loading $plans for
                     anyone who fails this, so there is nothing here to leak. --}}
                @can('billing.view')
                    @php $plan = $plans[$enrollment->user_id.'-'.$enrollment->course_id] ?? null; @endphp
                    <td class="td" style="white-space: nowrap;">
                        @if ($plan)
                            <a href="{{ route('admin.billing.plans.show', $plan) }}" class="font-mono text-xs font-medium text-primary hover:underline">
                                {{ $plan->paid_invoices }}/{{ $plan->total_invoices }} paid
                            </a>
                            <p class="font-mono text-[11px] text-outline">Rs {{ number_format((float) $plan->total_amount) }}</p>
                        @elseif ($enrollment->user)
                            @can('enrollments.create')
                                <x-btn variant="secondary" size="sm"
                                    :href="route('admin.enrollments.create', ['enroll' => $enrollment->user_id, 'pick' => $enrollment->course_id])"
                                    title="Generate the fee for this enrollment">
                                    <x-icon name="plus" class="size-3.5" /> Add fee
                                </x-btn>
                            @else
                                <span class="font-mono text-xs text-warning">no fee plan</span>
                            @endcan
                        @else
                            <span class="font-mono text-xs text-outline">—</span>
                        @endif
                    </td>
                @endcan
                <td class="td">
                    @if ($enrollment->completed_at)
                        <x-badge variant="success">completed</x-badge>
                    @else
                        <x-badge variant="primary">in progress</x-badge>
                    @endif
                </td>
                <td class="td">
                    <div class="flex items-center justify-end">
                        @can('enrollments.delete')
                            <x-confirm-form :action="route('admin.enrollments.destroy', $enrollment)" method="DELETE"
                                title="Remove enrollment" :message="'Unenroll '.($enrollment->user?->name ?? 'this student').' from '.($enrollment->course?->title ?? 'the course').'?'" confirm-label="Unenroll"
                                class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                <x-icon name="trash" class="size-4" />
                            </x-confirm-form>
                        @endcan
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                {{-- Follows the column count, or the empty state stops
                     spanning the table the moment Fee is hidden. --}}
                <td colspan="{{ auth()->user()?->can('billing.view') ? 7 : 6 }}">
                    <x-empty-state icon="user-plus" title="No enrollments found" description="Enroll a student to get things moving." />
                </td>
            </tr>
        @endforelse
    </tbody>
    <x-slot:footer>
        <div data-pagination>{{ $enrollments->links() }}</div>
    </x-slot:footer>
</x-table>
