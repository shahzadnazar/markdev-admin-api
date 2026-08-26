    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Student</th>
                <th class="th">CNIC / Contact</th>
                <th class="th">Courses</th>
                <th class="th">Joined</th>
                <th class="th">Status</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($students as $student)
                <tr class="row">
                    <td class="td">
                        <div class="flex items-center gap-3">
                            @if ($student->avatar_url)
                                <img src="{{ $student->avatar_url }}" alt="" class="size-10 shrink-0 rounded-full object-cover">
                            @else
                                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary to-secondary font-display text-sm font-semibold text-white">
                                    {{ strtoupper(mb_substr($student->name, 0, 1)) }}
                                </span>
                            @endif
                            <div class="min-w-0">
                                @if ($student->trashed())
                                    <p class="truncate font-medium text-on-surface">{{ $student->name }}</p>
                                @else
                                    <a href="{{ route('admin.students.show', $student) }}"
                                        class="block truncate font-medium text-on-surface hover:text-primary">{{ $student->name }}</a>
                                @endif
                                <p class="truncate font-mono text-[11px] text-outline">
                                    {{ $student->studentProfile?->reg_no ?? $student->email }}
                                </p>
                            </div>
                        </div>
                    </td>
                    <td class="td">
                        <p class="font-mono text-xs text-on-surface">{{ $student->studentProfile?->cnic ?? '—' }}</p>
                        <p class="font-mono text-xs text-outline">{{ $student->phone ?? $student->email }}</p>
                    </td>
                    <td class="td" style="max-width: 14rem;">
                        @if ($student->enrollments->isEmpty())
                            <span class="font-mono text-xs text-outline">not enrolled</span>
                        @else
                            <p class="truncate text-sm font-medium text-on-surface"
                                title="{{ $student->enrollments->map(fn ($enrollment) => $enrollment->course?->title)->filter()->implode(', ') }}">
                                {{ \Illuminate\Support\Str::limit($student->enrollments->first()->course?->title ?? '—', 26, '…') }}
                            </p>
                            @if ($student->enrollments->count() > 1)
                                <p class="mt-0.5 font-mono text-[11px] text-primary">+{{ $student->enrollments->count() - 1 }} more course{{ $student->enrollments->count() > 2 ? 's' : '' }}</p>
                            @endif
                        @endif
                    </td>
                    <td class="td font-mono text-xs text-on-surface-variant" style="white-space: nowrap;">
                        {{ ($student->studentProfile?->date_of_joining ?? $student->created_at)?->format('M j, Y') }}
                    </td>
                    <td class="td">
                        @if ($student->trashed())
                            <x-badge variant="danger">Removed</x-badge>
                        @else
                            <x-badge :variant="$student->is_active ? 'success' : 'neutral'">
                                {{ $student->is_active ? 'active' : 'inactive' }}
                            </x-badge>
                        @endif
                    </td>
                    <td class="td text-right">
                        <div class="inline-flex items-center gap-1">
                            @if ($student->trashed())
                                @can('students.delete')
                                    <form method="POST" action="{{ route('admin.students.restore', $student) }}" class="inline-flex">
                                        @csrf
                                        <x-btn variant="success" size="sm" title="Restore student">
                                            <x-icon name="restore" class="size-4" /> Restore
                                        </x-btn>
                                    </form>
                                    <x-confirm-form :action="route('admin.students.force-destroy', $student)" method="DELETE" variant="danger"
                                        :title="'Permanently remove ' . $student->name . '?'"
                                        :message="'This cannot be undone — the student\'s account and records are removed for good.'"
                                        confirm-label="Remove permanently"
                                        class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                        <x-icon name="trash" class="size-4" />
                                    </x-confirm-form>
                                @endcan
                            @else
                                <x-btn variant="ghost" size="sm" :href="route('admin.students.show', $student)" title="View profile">
                                    <x-icon name="eye" class="size-4" />
                                </x-btn>
                                @can('students.update')
                                    <x-btn variant="ghost" size="sm" :href="route('admin.students.edit', $student)" title="Edit">
                                        <x-icon name="pencil" class="size-4" />
                                    </x-btn>
                                @endcan
                                @can('students.delete')
                                    <x-confirm-form :action="route('admin.students.destroy', $student)" method="DELETE"
                                        title="Move student to trash?"
                                        :message="'Move '.$student->name.' to trash? They lose portal access; restore from Users → Trashed.'"
                                        confirm-label="Move to trash"
                                        class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                        <x-icon name="trash" class="size-4" />
                                    </x-confirm-form>
                                @endcan
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty-state icon="users" title="No students found"
                    description="Register your first student, or adjust the filters." /></td></tr>
            @endforelse
        </tbody>
        @if ($students->hasPages() || $students->total() > 0)
            <x-slot:footer>
                <div data-pagination>{{ $students->links() }}</div>
            </x-slot:footer>
        @endif
    </x-table>
