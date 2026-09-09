<x-table>
    <thead class="bg-surface-ice/60">
        <tr>
            <th class="th">Student</th>
            <th class="th">CNIC / Contact</th>
            <th class="th">Current courses</th>
            <th class="th text-right">Action</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($students as $student)
            @php
                $payload = [
                    'id' => $student->id,
                    'name' => $student->name,
                    'reg' => $student->studentProfile?->reg_no,
                    'avatar' => $student->avatar_url,
                    'enrolled' => $student->enrollments->pluck('course_id')->all(),
                    'planned' => $student->feePlans->pluck('course_id')->filter()->values()->all(),
                ];

                if ($autoOpen === $student->id) {
                    $autoPayload = $payload;
                }
            @endphp
            <tr class="row">
                <td class="td">
                    <div class="flex items-center gap-3">
                        @if ($student->avatar_url)
                            <img src="{{ $student->avatar_url }}" alt="" style="width: 2.5rem; height: 2.5rem;" class="shrink-0 rounded-full object-cover">
                        @else
                            <span style="width: 2.5rem; height: 2.5rem;" class="flex shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary to-secondary font-display text-sm font-semibold text-white">
                                {{ strtoupper(mb_substr($student->name, 0, 1)) }}
                            </span>
                        @endif
                        <div class="min-w-0">
                            <p class="truncate font-medium text-on-surface">{{ $student->name }}</p>
                            <p class="truncate font-mono text-[11px] text-outline">{{ $student->studentProfile?->reg_no ?? $student->email }}</p>
                        </div>
                    </div>
                </td>
                <td class="td">
                    <p class="font-mono text-xs text-on-surface">{{ $student->studentProfile?->cnic ?? '—' }}</p>
                    <p class="font-mono text-xs text-outline">{{ $student->phone ?? $student->email }}</p>
                </td>
                <td class="td" style="max-width: 15rem;">
                    @if ($student->enrollments->isEmpty())
                        <span class="font-mono text-xs text-outline">not enrolled</span>
                    @else
                        <p class="truncate text-sm text-on-surface"
                            title="{{ $student->enrollments->map(fn ($enrollment) => $enrollment->course?->title)->filter()->implode(', ') }}">
                            {{ \Illuminate\Support\Str::limit($student->enrollments->first()->course?->title ?? '—', 26, '…') }}
                        </p>
                        @if ($student->enrollments->count() > 1)
                            <p class="mt-0.5 font-mono text-[11px] text-primary">+{{ $student->enrollments->count() - 1 }} more</p>
                        @endif
                    @endif
                </td>
                <td class="td text-right">
                    <button type="button" x-on:click='openEnroll(@json($payload))'
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-medium text-white shadow-card transition hover:bg-primary-deep">
                        <x-icon name="user-plus" class="size-4" /> Enroll now
                    </button>
                </td>
            </tr>
        @empty
            <tr><td colspan="4"><x-empty-state icon="users" title="No students match"
                description="Adjust the search or filters — only active students are listed." /></td></tr>
        @endforelse
    </tbody>
    @if ($students->hasPages() || $students->total() > 0)
        <x-slot:footer>
            <div data-pagination>{{ $students->links() }}</div>
        </x-slot:footer>
    @endif
</x-table>
