<x-admin.layout title="Daily attendance">
    @php
        $statusMeta = [
            'present' => ['label' => 'Present', 'badge' => 'success'],
            'late' => ['label' => 'Late', 'badge' => 'warning'],
            'absent' => ['label' => 'Absent', 'badge' => 'danger'],
            'leave' => ['label' => 'Leave', 'badge' => 'secondary'],
        ];
        $roleLabel = fn ($user) => $user?->roles?->first()
            ? \Illuminate\Support\Str::headline($user->roles->first()->name)
            : null;
    @endphp

    <x-page-header eyebrow="Learning" title="Daily attendance"
        :description="'Register for '.$date->format('l, F j, Y').($date->isToday() ? ' — today' : '').'. Every active student, marked once; corrections need the security PIN and a reason.'">
        <x-slot:actions>
            <x-btn variant="secondary" :href="route('admin.attendance.daily.print', request()->query())" target="_blank">
                <x-icon name="printer" class="size-4" /> Print PDF
            </x-btn>
            @if ($counts['unmarked'] > 0)
                <x-confirm-form :action="route('admin.attendance.daily.bulk', ['date' => $date->toDateString()])" method="POST" variant="primary"
                    title="Mark remaining present?"
                    :message="$counts['unmarked'].' student(s) are still unmarked for '.$date->format('M j').'. Mark them all present now? Individual corrections stay possible via Update.'"
                    confirm-label="Mark all present"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-white shadow-card transition hover:bg-primary-deep">
                    <x-icon name="check" class="size-4" /> Mark remaining present
                </x-confirm-form>
            @endif
        </x-slot:actions>
    </x-page-header>

    @unless ($pinConfigured)
        <div class="mb-5 flex items-start gap-3 rounded-xl border border-warning/30 bg-warning/10 px-4 py-3.5 text-sm text-on-surface">
            <x-icon name="warning" class="mt-0.5 size-4.5 shrink-0 text-warning" />
            <p><span class="font-semibold">No attendance security PIN is set.</span> Marking works, but corrections stay locked until a Super Admin sets the PIN in System → Settings.</p>
        </div>
    @endunless

    {{-- Compact day summary --}}
    <x-attendance.summary-strip :counts="$counts" class="mb-5" />

    {{-- Filters — compact, all optional except the date --}}
    <form method="GET" action="{{ route('admin.attendance.daily') }}" class="mb-5 flex flex-wrap items-end gap-2.5">
        <div>
            <label class="mb-1 block text-xs font-medium text-on-surface-variant" for="date">Date</label>
            <input type="date" name="date" id="date" value="{{ $date->toDateString() }}" max="{{ today()->toDateString() }}" class="field h-9 w-38 text-sm">
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-on-surface-variant" for="status">Status</label>
            <select name="status" id="status" class="field h-9 w-32 text-sm">
                <option value="">All</option>
                <option value="unmarked" @selected($statusFilter === 'unmarked')>Not marked</option>
                @foreach ($statusMeta as $key => $meta)
                    <option value="{{ $key }}" @selected($statusFilter === $key)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-on-surface-variant" for="course">Course <span class="font-normal text-outline">(optional)</span></label>
            <select name="course" id="course" class="field h-9 w-48 text-sm">
                <option value="">All courses</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected($courseId === $course->id)>{{ $course->title }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-on-surface-variant" for="search">Search</label>
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Name, reg #, CNIC…" class="field h-9 w-52 text-sm">
        </div>
        <x-btn variant="secondary" size="sm" class="h-9"><x-icon name="funnel" class="size-3.5" /> Apply</x-btn>
        @if (request()->hasAny(['status', 'course', 'search']))
            <x-btn variant="ghost" size="sm" class="h-9" :href="route('admin.attendance.daily', ['date' => $date->toDateString()])">Clear</x-btn>
        @endif
    </form>

    <div x-data="dailyAttendance()">
        <x-table>
            <thead class="bg-surface-ice/60">
                <tr>
                    <th class="th">Student</th>
                    <th class="th">Course</th>
                    <th class="th">Attendance</th>
                    <th class="th">Remarks</th>
                    <th class="th">Marked</th>
                    <th class="th text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($students as $student)
                    @php
                        $record = $records->get($student->id);
                        $payload = [
                            'student' => [
                                'id' => $student->id,
                                'name' => $student->name,
                                'reg' => $student->studentProfile?->reg_no,
                                'avatar' => $student->avatar_url,
                            ],
                            'record' => $record ? [
                                'id' => $record->id,
                                'status' => $record->status,
                                'remarks' => $record->remarks,
                                'source' => $record->source,
                                'marked_at' => $record->marked_at->format('D, M j · g:i A'),
                                'marked_by' => $record->source === 'biometric'
                                    ? 'Biometric device'
                                    : trim(($record->marker?->name ?? 'System').($roleLabel($record->marker) ? ' — '.$roleLabel($record->marker) : '')),
                                'updated_at' => $record->last_updated_at?->format('D, M j · g:i A'),
                                'updated_by' => $record->updater?->name,
                                'update_reason' => $record->last_update_reason,
                            ] : null,
                        ];
                    @endphp
                    <tr class="row" @if ($record && session('reopen_update') == $record->id)
                        x-init='openUpdate(@json($payload))'
                    @endif>
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
                                    <a href="{{ route('admin.attendance.daily.show', $student) }}"
                                        class="block truncate font-medium text-on-surface hover:text-primary">{{ $student->name }}</a>
                                    <p class="truncate font-mono text-[11px] text-outline">{{ $student->studentProfile?->reg_no ?? $student->email }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="td max-w-[13rem]">
                            @if ($student->enrollments->isEmpty())
                                <span class="font-mono text-xs text-outline">—</span>
                            @else
                                <div class="flex flex-wrap gap-1">
                                    <x-badge variant="primary" :title="$student->enrollments->first()->course?->title">
                                        <span class="inline-block max-w-32 truncate align-bottom normal-case">{{ $student->enrollments->first()->course?->title }}</span>
                                    </x-badge>
                                    @if ($student->enrollments->count() > 1)
                                        <x-badge variant="neutral" :title="$student->enrollments->skip(1)->map(fn ($enrollment) => $enrollment->course?->title)->filter()->implode(', ')">
                                            +{{ $student->enrollments->count() - 1 }}
                                        </x-badge>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="td">
                            @if ($record)
                                <x-badge :variant="$statusMeta[$record->status]['badge'] ?? 'neutral'">{{ $statusMeta[$record->status]['label'] ?? $record->status }}</x-badge>
                                @if ($record->last_updated_at)
                                    <p class="mt-1 font-mono text-[10px] uppercase tracking-wide text-outline">corrected</p>
                                @endif
                            @else
                                <x-badge variant="neutral">Not marked</x-badge>
                            @endif
                        </td>
                        <td class="td max-w-[12rem]">
                            <p class="truncate text-sm text-on-surface-variant" title="{{ $record?->remarks }}">{{ $record?->remarks ?? '—' }}</p>
                        </td>
                        <td class="td">
                            @if ($record)
                                <p class="font-mono text-xs text-on-surface">{{ $record->marked_at->format('g:i A') }}</p>
                                <p class="truncate font-mono text-[11px] text-outline" title="{{ $record->source === 'biometric' ? 'Biometric device' : $record->marker?->name }}">
                                    @if ($record->source === 'biometric')
                                        Biometric
                                    @else
                                        {{ $roleLabel($record->marker) ?? $record->marker?->name ?? '—' }}
                                    @endif
                                </p>
                            @else
                                <span class="font-mono text-xs text-outline">—</span>
                            @endif
                        </td>
                        <td class="td text-right">
                            @if (! $record)
                                <button type="button" x-on:click='openMark(@json($payload))'
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-success/10 px-3 py-2 text-sm font-medium text-success transition hover:bg-success/20">
                                    <x-icon name="plus" class="size-4" /> Mark
                                </button>
                            @else
                                <div class="inline-flex items-center gap-1">
                                    <a href="{{ route('admin.attendance.daily.show', $student) }}" title="Open attendance history"
                                        class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                        <x-icon name="eye" class="size-4" />
                                    </a>
                                    <button type="button" x-on:click='openUpdate(@json($payload))' title="Update (PIN required)"
                                        class="rounded-lg p-2 text-on-surface-variant transition hover:bg-warning/15 hover:text-warning">
                                        <x-icon name="pencil" class="size-4" />
                                    </button>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty-state icon="users" title="No students match"
                        description="Adjust the date, status, course or search filters." /></td></tr>
                @endforelse
            </tbody>
            @if ($students->hasPages() || $students->total() > 0)
                <x-slot:footer>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-xs text-outline">
                            Showing <span class="font-semibold text-on-surface">{{ $students->firstItem() ?? 0 }}–{{ $students->lastItem() ?? 0 }}</span>
                            of <span class="font-semibold text-on-surface">{{ $students->total() }}</span> active students
                        </p>
                        {{ $students->links() }}
                    </div>
                </x-slot:footer>
            @endif
        </x-table>

        {{-- ······························ Modal ······························ --}}
        <template x-teleport="body">
            <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
                x-on:keydown.escape.window="close()">
                <div x-show="open" x-transition.opacity class="absolute inset-0 bg-primary-deep/20 backdrop-blur-[2px]" x-on:click="close()"></div>

                <div x-show="open" x-transition class="relative flex max-h-[88vh] w-full max-w-md flex-col overflow-hidden rounded-2xl bg-white shadow-elevated">
                    {{-- Header --}}
                    <div class="flex shrink-0 items-center gap-3 border-b border-surface-ice px-5 py-3.5">
                        <template x-if="student.avatar">
                            <img :src="student.avatar" alt="" class="size-10 shrink-0 rounded-full object-cover">
                        </template>
                        <template x-if="! student.avatar">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary to-secondary font-display text-sm font-semibold text-white"
                                x-text="(student.name || '?').charAt(0).toUpperCase()"></span>
                        </template>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-display text-[15px] font-semibold text-on-surface" x-text="student.name"></p>
                            <p class="font-mono text-[11px] text-outline">
                                <span x-text="student.reg || ''"></span> · {{ $date->format('D, M j, Y') }}
                            </p>
                        </div>
                        <button type="button" x-on:click="close()" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-surface-ice">
                            <x-icon name="x-mark" class="size-4" />
                        </button>
                    </div>

                    <div class="min-h-0 overflow-y-auto">
                        {{-- ---- Mark (first time — no PIN) ---- --}}
                        <form x-show="mode === 'mark'" method="POST" action="{{ route('admin.attendance.daily.mark') }}" class="space-y-4 px-5 py-4">
                            @csrf
                            <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                            <input type="hidden" name="user_id" :value="student.id">

                            <div>
                                <p class="mb-2 text-[13px] font-medium text-on-surface">Status</p>
                                <div class="grid grid-cols-4 gap-1.5">
                                    @foreach ($statusMeta as $key => $meta)
                                        <label class="choice-pill flex cursor-pointer items-center justify-center rounded-lg border border-outline/30 px-1.5 py-2 text-[13px] font-medium text-on-surface-variant transition hover:border-primary/50 hover:text-primary">
                                            <input type="radio" name="status" value="{{ $key }}" class="sr-only" required @checked($key === 'present')>
                                            {{ $meta['label'] }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <div>
                                <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="mark-remarks">Remarks <span class="font-normal text-outline">(optional)</span></label>
                                <textarea name="remarks" id="mark-remarks" rows="2" maxlength="500" class="field w-full text-sm"
                                    placeholder="e.g. informed leave, arrived late…"></textarea>
                            </div>

                            <p class="rounded-lg bg-surface-ice/70 px-3 py-2 font-mono text-[11px] text-on-surface-variant">
                                {{ now()->format('D, M j · g:i A') }} · by {{ auth()->user()->name }} ({{ \Illuminate\Support\Str::headline(auth()->user()->roles->first()?->name ?? 'Staff') }})
                            </p>

                            <div class="flex justify-end gap-2.5 pb-1">
                                <x-btn type="button" variant="ghost" size="sm" x-on:click="close()">Cancel</x-btn>
                                <x-btn type="submit" size="sm"><x-icon name="check" class="size-4" /> Save attendance</x-btn>
                            </div>
                        </form>

                        {{-- ---- Update (PIN + reason) ---- --}}
                        <form x-show="mode === 'update'" method="POST" :action="'{{ url('admin/attendance/daily') }}/' + (record.id || '')" class="space-y-4 px-5 py-4">
                            @csrf
                            @method('PUT')

                            {{-- Current state, for context --}}
                            <div class="flex items-center gap-2.5 rounded-lg bg-surface-ice/70 px-3 py-2 text-xs text-on-surface-variant">
                                <span class="rounded-full px-2 py-0.5 font-mono text-[10px] font-semibold uppercase" :class="badgeClass(record.status)" x-text="record.status"></span>
                                <span class="truncate" x-text="'marked ' + (record.marked_at || '') + ' · ' + (record.marked_by || '')"></span>
                            </div>
                            <template x-if="record.updated_at">
                                <p class="rounded-lg bg-warning/10 px-3 py-2 text-xs text-on-surface-variant"
                                    x-text="'Last corrected ' + record.updated_at + ' by ' + (record.updated_by || '—') + ' — ' + (record.update_reason || '')"></p>
                            </template>

                            <div class="rounded-xl border border-warning/30 bg-warning/10 p-3.5">
                                <label class="mb-1.5 flex items-center gap-2 text-[13px] font-medium text-on-surface" for="update-pin">
                                    <x-icon name="shield" class="size-4 text-warning" /> Security PIN
                                </label>
                                <input type="password" name="pin" id="update-pin" x-model="pin" inputmode="numeric" autocomplete="one-time-code"
                                    maxlength="8" class="field h-9 w-36 tracking-[0.3em]" placeholder="••••" required>
                                @error('pin')
                                    <p class="mt-1.5 text-xs font-medium text-error">{{ $message }}</p>
                                @enderror
                                <p class="mt-1.5 text-xs text-on-surface-variant">Corrections are locked behind the academy PIN and always logged.</p>
                            </div>

                            <div x-show="pin.length >= 4" x-transition class="space-y-4">
                                <div>
                                    <p class="mb-2 text-[13px] font-medium text-on-surface">New status</p>
                                    <div class="grid grid-cols-4 gap-1.5">
                                        @foreach ($statusMeta as $key => $meta)
                                            <label class="choice-pill flex cursor-pointer items-center justify-center rounded-lg border border-outline/30 px-1.5 py-2 text-[13px] font-medium text-on-surface-variant transition hover:border-primary/50 hover:text-primary">
                                                <input type="radio" name="status" value="{{ $key }}" class="sr-only" x-model="newStatus">
                                                {{ $meta['label'] }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                <div>
                                    <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="update-remarks">Remarks <span class="font-normal text-outline">(optional)</span></label>
                                    <textarea name="remarks" id="update-remarks" rows="2" maxlength="500" class="field w-full text-sm" x-model="remarks"></textarea>
                                </div>

                                <div>
                                    <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="update-reason">
                                        Reason for this correction <span class="text-error">*</span>
                                    </label>
                                    <textarea name="reason" id="update-reason" rows="2" maxlength="500" class="field w-full text-sm" required minlength="3"
                                        placeholder="e.g. marked by mistake — student was on approved leave">{{ old('reason') }}</textarea>
                                    @error('reason')
                                        <p class="mt-1.5 text-xs font-medium text-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="flex justify-end gap-2.5 pb-1">
                                <x-btn type="button" variant="ghost" size="sm" x-on:click="close()">Cancel</x-btn>
                                <x-btn type="submit" size="sm" x-bind:disabled="pin.length < 4">
                                    <x-icon name="check" class="size-4" /> Verify PIN &amp; update
                                </x-btn>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <script>
        function dailyAttendance() {
            return {
                open: false,
                mode: 'mark',
                student: {},
                record: {},
                pin: '',
                newStatus: 'present',
                remarks: '',
                openMark(payload) {
                    this.student = payload.student;
                    this.record = {};
                    this.mode = 'mark';
                    this.open = true;
                },
                openUpdate(payload) {
                    this.student = payload.student;
                    this.record = payload.record || {};
                    this.pin = '';
                    this.newStatus = this.record.status || 'present';
                    this.remarks = this.record.remarks || '';
                    this.mode = 'update';
                    this.open = true;
                },
                close() {
                    this.open = false;
                    this.pin = '';
                },
                badgeClass(status) {
                    return {
                        present: 'bg-success/10 text-success',
                        late: 'bg-warning/15 text-warning',
                        absent: 'bg-error/10 text-error',
                        leave: 'bg-secondary/10 text-secondary',
                    }[status] || 'bg-surface-ice text-on-surface-variant';
                },
            };
        }
    </script>
</x-admin.layout>
