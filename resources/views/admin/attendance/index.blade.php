<x-admin.layout title="Attendance">
    <x-page-header eyebrow="Learning" title="Attendance" description="Mark a course's register for any date.">
        <x-slot:actions>
            <x-btn variant="secondary" :href="route('admin.attendance.log')">
                <x-icon name="clipboard" class="size-4" /> Recent records
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar :action="route('admin.attendance.index')">
        <div class="w-72">
            <x-form.label for="course" value="Course" />
            <select name="course" id="course" class="field" required>
                <option value="">Choose a course…</option>
                @foreach ($courses as $option)
                    <option value="{{ $option->id }}" @selected($course?->id === $option->id)>{{ $option->title }}</option>
                @endforeach
            </select>
        </div>
        <div class="w-48">
            <x-form.label for="date" value="Date" />
            <input type="date" name="date" id="date" value="{{ $date->format('Y-m-d') }}" class="field">
        </div>
    </x-filter-bar>

    @if (! $course)
        <x-card>
            <x-empty-state icon="calendar" title="Pick a course and date" description="Choose a course above to load its enrolled students and mark the register." />
        </x-card>
    @elseif ($students->isEmpty())
        <x-card>
            <x-empty-state icon="users" title="No enrolled students" description="Nobody is enrolled in {{ $course->title }} yet." />
        </x-card>
    @else
        <form method="POST" action="{{ route('admin.attendance.save') }}">
            @csrf
            <input type="hidden" name="course_id" value="{{ $course->id }}">
            <input type="hidden" name="date" value="{{ $date->format('Y-m-d') }}">

            <x-card class="mb-4 max-w-xl">
                <x-form.input label="Session title (optional)" name="session_title"
                    :value="$existing->first()?->session_title ?? 'Live session — '.$date->format('M j')"
                    hint="Shown on the student's attendance record." />
            </x-card>

            <x-table>
                <thead class="bg-surface-ice/60">
                    <tr>
                        <th class="th">Student</th>
                        <th class="th">Status</th>
                        <th class="th">Notes</th>
                        <th class="th text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($students as $student)
                        @php $record = $existing->get($student->id); @endphp
                        <tr class="row">
                            <td class="td w-72">
                                <input type="hidden" name="rows[{{ $loop->index }}][user_id]" value="{{ $student->id }}">
                                <p class="font-medium text-on-surface">{{ $student->name }}</p>
                                <p class="text-xs text-outline">{{ $student->email }}</p>
                            </td>
                            <td class="td">
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach (['present' => 'success', 'late' => 'warning', 'absent' => 'danger', 'excused' => 'neutral'] as $status => $tone)
                                        <label class="cursor-pointer">
                                            <input type="radio" name="rows[{{ $loop->parent->index }}][status]" value="{{ $status }}"
                                                @checked(($record->status ?? null) === $status) class="peer sr-only">
                                            <span class="inline-flex items-center rounded-full border px-3 py-1.5 font-mono text-[11px] font-medium uppercase tracking-[0.05em] transition
                                                {{ [
                                                    'success' => 'peer-checked:border-success peer-checked:bg-success-container peer-checked:text-success',
                                                    'warning' => 'peer-checked:border-warning peer-checked:bg-warning-container peer-checked:text-warning',
                                                    'danger' => 'peer-checked:border-error peer-checked:bg-error-container peer-checked:text-error',
                                                    'neutral' => 'peer-checked:border-outline peer-checked:bg-on-surface-variant/10 peer-checked:text-on-surface-variant',
                                                ][$tone] }} border-outline-variant/60 text-outline hover:border-outline">
                                                {{ $status }}
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </td>
                            <td class="td">
                                <input type="text" name="rows[{{ $loop->index }}][notes]" value="{{ $record->notes ?? '' }}"
                                    placeholder="Optional note…" class="field max-w-xs">
                            </td>
                            <td class="td text-right">
    <a
        href="{{ route('admin.attendance.daily.show', $student) }}"
        title="View attendance history"
        aria-label="View attendance history"
        class="inline-flex items-center justify-center rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary"
    >
        <x-icon name="eye" class="size-4" />
    </a>
</td>
                        </tr>
                    @endforeach
                </tbody>
                <x-slot:footer>
                    <div class="flex items-center justify-between gap-4">
                        <p class="text-xs text-outline">{{ $students->count() }} student(s) · {{ $date->format('l, M j, Y') }}</p>
                        <x-btn>
                            <x-icon name="check" class="size-4" /> Save register
                        </x-btn>
                    </div>
                </x-slot:footer>
            </x-table>
        </form>
    @endif
</x-admin.layout>
