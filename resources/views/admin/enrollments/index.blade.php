<x-admin.layout title="Enrollments">
    <x-page-header eyebrow="Learning" title="Enrollments" description="Who is enrolled where, and how far along they are.">
        <x-slot:actions>
            @can('enrollments.create')
                <x-btn :href="route('admin.enrollments.create')">
                    <x-icon name="plus" class="size-4" /> Enroll a student
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar :action="route('admin.enrollments.index')">
        <div class="w-full sm:w-64">
            <x-form.label for="search" value="Student" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Name or email…" class="field">
        </div>
        <div class="w-64">
            <x-form.label for="course" value="Course" />
            <select name="course" id="course" class="field">
                <option value="">All courses</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected(request('course') == $course->id)>{{ $course->title }}</option>
                @endforeach
            </select>
        </div>
    </x-filter-bar>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Student</th>
                <th class="th">Course</th>
                <th class="th">Enrolled</th>
                <th class="th">Progress</th>
                <th class="th">Fee</th>
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
                    <td class="td font-mono text-xs text-outline">{{ $enrollment->enrolled_at?->format('M j, Y') }}</td>
                    <td class="td">
                        <div class="flex items-center gap-2.5">
                            <div class="h-1.5 w-24 overflow-hidden rounded-full bg-surface-ice">
                                <div class="h-full rounded-full bg-gradient-to-r from-primary to-secondary" style="width: {{ min(100, (float) $enrollment->progress_percent) }}%"></div>
                            </div>
                            <span class="font-mono text-xs text-on-surface-variant">{{ round((float) $enrollment->progress_percent) }}%</span>
                        </div>
                    </td>
                    @php $plan = $plans[$enrollment->user_id.'-'.$enrollment->course_id] ?? null; @endphp
                    <td class="td" style="white-space: nowrap;">
                        @if ($plan)
                            @can('billing.view')
                                <a href="{{ route('admin.billing.plans.show', $plan) }}" class="font-mono text-xs font-medium text-primary hover:underline">
                                    {{ $plan->paid_invoices }}/{{ $plan->total_invoices }} paid
                                </a>
                            @else
                                <span class="font-mono text-xs text-on-surface">{{ $plan->paid_invoices }}/{{ $plan->total_invoices }} paid</span>
                            @endcan
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
                    <td colspan="7">
                        <x-empty-state icon="user-plus" title="No enrollments found" description="Enroll a student to get things moving." />
                    </td>
                </tr>
            @endforelse
        </tbody>
        <x-slot:footer>
            {{ $enrollments->links() }}
        </x-slot:footer>
    </x-table>
</x-admin.layout>
