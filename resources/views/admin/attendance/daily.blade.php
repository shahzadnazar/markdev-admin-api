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

    <x-page-header title="Daily attendance"
        description="One record per active student per day — corrections need the security PIN and a reason."
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Attendance' => null, 'Daily register' => null]">
        <x-slot:meta>
            <span class="font-mono text-xs text-on-surface-variant">{{ $date->format('D, M j, Y') }}{{ $date->isToday() ? ' · today' : '' }}</span>
            @if ($counts['weighted_percent'] !== null)
                {{-- Weighted, not a headcount: present 100, late 70, leave 50, absent 0. --}}
                <span class="ml-3 inline-flex items-center gap-1.5 rounded-full bg-primary/[0.06] px-2.5 py-1 font-mono text-xs text-primary ring-1 ring-inset ring-primary/10"
                    title="Weighted: present 100%, late 70%, leave 50%, absent 0%">
                    {{ $counts['weighted_percent'] }}% attendance
                </span>
            @endif
        </x-slot:meta>
        <x-slot:actions>
            <x-btn variant="secondary" size="sm" :href="route('admin.attendance.daily.print', request()->query())" target="_blank">
                <x-icon name="printer" class="size-4" /> Print PDF
            </x-btn>
            @if ($counts['unmarked'] > 0)
            <x-confirm-form :action="route('admin.attendance.daily.bulk', ['date' => $date->toDateString()])" method="POST" variant="primary"
                title="Mark remaining present?"
                :message="$counts['unmarked'].' student(s) are still unmarked for '.$date->format('M j').'. Mark them all present now? Individual corrections stay possible via Update.'"
                confirm-label="Mark all present"
                class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-white shadow-card transition hover:bg-primary-deep">
                <x-icon name="check" class="size-3.5" /> Mark all present ({{ $counts['unmarked'] }})
            </x-confirm-form>
            @endif
        </x-slot:actions>
    </x-page-header>

    @unless ($pinConfigured)
    <div class="mb-4 flex items-center gap-2.5 rounded-lg border border-warning/30 bg-warning/10 px-3.5 py-2 text-[13px] text-on-surface">
        <x-icon name="warning" class="size-4 shrink-0 text-warning" />
        <p><span class="font-semibold">No attendance security PIN is set</span> — corrections stay locked until a Super Admin sets one in System → Settings.</p>
    </div>
    @endunless

    {{-- Toolbar: filters + day summary in one card --}}
    <x-card :padding="false" class="mb-4">
        <form method="GET" action="{{ route('admin.attendance.daily') }}"
            class="flex flex-wrap items-center gap-2 border-b border-surface-ice px-4 py-2.5">
            <label class="sr-only" for="date">Attendance date</label>
            <input type="date" name="date" id="date" value="{{ $date->toDateString() }}" max="{{ today()->toDateString() }}"
                class="field h-9 w-38 text-sm" title="Attendance date">
            <label class="sr-only" for="status">Status</label>
            <select name="status" id="status" class="field h-9 w-36 text-sm" title="Status">
                <option value="">All statuses</option>
                <option value="unmarked" @selected($statusFilter==='unmarked' )>Not marked</option>
                @foreach ($statusMeta as $key => $meta)
                <option value="{{ $key }}" @selected($statusFilter===$key)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="course">Course (optional)</label>
            <select name="course" id="course" class="field h-9 w-48 text-sm" title="Course — optional">
                <option value="">All courses</option>
                @foreach ($courses as $course)
                <option value="{{ $course->id }}" @selected($courseId===$course->id)>{{ $course->title }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="search">Search</label>
            <input type="search" name="search" id="search" value="{{ request('search') }}"
                placeholder="Name, reg #, CNIC…" class="field h-9 w-44 min-w-0 flex-1 text-sm sm:max-w-56">
            <x-btn variant="secondary" size="sm" class="h-9"><x-icon name="funnel" class="size-3.5" /> Apply</x-btn>
            @if (request()->hasAny(['status', 'course', 'search']))
            <x-btn variant="ghost" size="sm" class="h-9" :href="route('admin.attendance.daily', ['date' => $date->toDateString()])">Clear</x-btn>
            @endif
        </form>
        <x-attendance.summary-strip :counts="$counts" class="px-4 py-2.5" />
    </x-card>

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
                $studentHistory = $previousAttendance->get($student->id, collect());
                $payload = [
                'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'reg' => $student->studentProfile?->reg_no,
                'avatar' => $student->avatar_url,
                ],

                'history' => [
                'present' => $studentHistory->where('status', 'present')->count(),
                'late' => $studentHistory->where('status', 'late')->count(),
                'absent' => $studentHistory->where('status', 'absent')->count(),
                'leave' => $studentHistory->where('status', 'leave')->count(),

                'total' => $studentHistory->count(),

                'rate' => $studentHistory->count() > 0
                ? round(
                (
                $studentHistory->whereIn('status', ['present', 'late', 'leave'])->count()
                / $studentHistory->count()
                ) * 100,
                1
                )
                : null,

                'recent' => $studentHistory->take(5)->map(fn ($attendance) => [
                'date' => $attendance->date->format('M j, Y'),
                'status' => $attendance->status,
                ])->values()->all(),
                ],

                'record' => $record ? [
                'id' => $record->id,
                'status' => $record->status,
                'remarks' => $record->remarks,
                'arrived' => $record->arrived_at
                ? \Illuminate\Support\Carbon::parse($record->arrived_at)->format('H:i')
                : '',
                'arrived_label' => $record->arrived_at
                ? \Illuminate\Support\Carbon::parse($record->arrived_at)->format('g:i A')
                : '',
                'marked_at' => $record->marked_at?->format('M j, Y g:i A'),
                'marked_by' => $record->source === 'biometric'
                ? 'Biometric'
                : ($record->marker?->name ?? '—'),
                'updated_at' => $record->last_updated_at?->format('M j, Y g:i A'),
                'updated_by' => $record->updater?->name ?? '—',
                'update_reason' => $record->last_update_reason,
                ] : null,
                ];
                @endphp
                <tr class="row" @if ($record && session('reopen_update')==$record->id)
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
                    <td class="td" style="max-width: 12rem;">
                        @if ($student->enrollments->isEmpty())
                        <span class="font-mono text-xs text-outline">—</span>
                        @else
                        <p class="truncate text-sm text-on-surface"
                            title="{{ $student->enrollments->map(fn ($enrollment) => $enrollment->course?->title)->filter()->implode(', ') }}">
                            {{ \Illuminate\Support\Str::limit($student->enrollments->first()->course?->title ?? '—', 24, '…') }}
                        </p>
                        @if ($student->enrollments->count() > 1)
                        <p class="mt-0.5 font-mono text-[11px] text-primary">+{{ $student->enrollments->count() - 1 }} more</p>
                        @endif
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
                    <td class="td">
                        <p class="text-sm text-on-surface-variant" title="{{ $record?->remarks }}">
                            {{ $record?->remarks ? \Illuminate\Support\Str::limit($record->remarks, 20, '…') : '—' }}
                        </p>
                    </td>
                    <td class="td">
                        @if ($record)
                        <p class="font-mono text-xs {{ $record->status === 'late' ? 'font-semibold text-warning' : 'text-on-surface' }}">
                            {{ $record->arrived_at ? \Illuminate\Support\Carbon::parse($record->arrived_at)->format('g:i A') : $record->marked_at->format('g:i A') }}
                        </p>
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
                        <div class="inline-flex items-center gap-1">

                            {{-- Always available: View student's attendance history --}}
                            <a href="{{ route('admin.attendance.daily.show', $student) }}"
                                title="Open attendance history"
                                aria-label="Open attendance history"
                                class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                <span class="text-base">👁</span>
                            </a>

                            {{-- Today's attendance --}}
                            @if (! $record)

                            <button type="button"
                                x-on:click='openMark(@json($payload))'
                                title="Mark today's attendance"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-success/10 px-3 py-2 text-sm font-medium text-success transition hover:bg-success/20">
                                <x-icon name="plus" class="size-4" />
                                Mark
                            </button>

                            @else

                            <button type="button"
                                x-on:click='openUpdate(@json($payload))'
                                title="Update today's attendance (PIN required)"
                                class="rounded-lg p-2 text-on-surface-variant transition hover:bg-warning/15 hover:text-warning">
                                <x-icon name="pencil" class="size-4" />
                            </button>

                            @endif

                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6"><x-empty-state icon="users" title="No students match"
                            description="Adjust the date, status, course or search filters." /></td>
                </tr>
                @endforelse
            </tbody>
            @if ($students->hasPages() || $students->total() > 0)
            <x-slot:footer>
                {{ $students->links() }}
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
                            <div class="rounded-xl border border-outline/15 bg-surface-ice/50 p-3.5">
                                <div class="mb-3 flex items-center justify-between">
                                    <p class="font-mono text-[10px] font-semibold uppercase tracking-[0.08em] text-outline">
                                        Previous attendance
                                    </p>

                                    <template x-if="history.total > 0">
                                        <span class="font-mono text-xs font-semibold text-primary">
                                            <span x-text="history.rate"></span>% attendance
                                        </span>
                                    </template>
                                </div>

                                <div class="grid grid-cols-4 gap-2">
                                    <div class="rounded-lg bg-success/10 px-2.5 py-2">
                                        <p class="font-display text-lg font-bold text-success"
                                            x-text="history.present"></p>
                                        <p class="font-mono text-[9px] uppercase tracking-wide text-outline">
                                            Present
                                        </p>
                                    </div>

                                    <div class="rounded-lg bg-warning/10 px-2.5 py-2">
                                        <p class="font-display text-lg font-bold text-warning"
                                            x-text="history.late"></p>
                                        <p class="font-mono text-[9px] uppercase tracking-wide text-outline">
                                            Late
                                        </p>
                                    </div>

                                    <div class="rounded-lg bg-error/10 px-2.5 py-2">
                                        <p class="font-display text-lg font-bold text-error"
                                            x-text="history.absent"></p>
                                        <p class="font-mono text-[9px] uppercase tracking-wide text-outline">
                                            Absent
                                        </p>
                                    </div>

                                    <div class="rounded-lg bg-secondary/10 px-2.5 py-2">
                                        <p class="font-display text-lg font-bold text-secondary"
                                            x-text="history.leave"></p>
                                        <p class="font-mono text-[9px] uppercase tracking-wide text-outline">
                                            Leave
                                        </p>
                                    </div>
                                </div>

                                <template x-if="history.recent.length > 0">
                                    <div class="mt-3 border-t border-outline/10 pt-3">
                                        <p class="mb-2 text-xs font-medium text-on-surface">
                                            Recent records
                                        </p>

                                        <div class="space-y-1">
                                            <template x-for="item in history.recent" :key="item.date">
                                                <div class="flex items-center justify-between rounded-md px-2 py-1.5 hover:bg-white">
                                                    <span class="font-mono text-[11px] text-outline"
                                                        x-text="item.date"></span>

                                                    <span
                                                        class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase"
                                                        :class="badgeClass(item.status)"
                                                        x-text="item.status">
                                                    </span>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>

                                <template x-if="history.total === 0">
                                    <p class="mt-3 border-t border-outline/10 pt-3 text-xs text-outline">
                                        No previous attendance records.
                                    </p>
                                </template>
                            </div>
                            @csrf
                            <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                            <input type="hidden" name="user_id" :value="student.id">

                            <div class="grid grid-cols-[1fr_8.5rem] gap-3">
                                <div>
                                    <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="mark-status">Status</label>
                                    <select name="status" id="mark-status" class="field w-full text-sm" required>
                                        @foreach ($statusMeta as $key => $meta)
                                        <option value="{{ $key }}" @selected($key==='present' )>{{ $meta['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="mark-arrived">Arrival <span class="font-normal text-outline">(opt.)</span></label>
                                    <input type="time" name="arrived_at" id="mark-arrived" class="field w-full text-sm">
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
                                <span class="truncate" x-text="(record.arrived_label ? record.arrived_label : (record.marked_at || '')) + ' · ' + (record.marked_by || '')"></span>
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
                                <div class="grid grid-cols-[1fr_8.5rem] gap-3">
                                    <div>
                                        <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="update-status">New status</label>
                                        <select name="status" id="update-status" class="field w-full text-sm" x-model="newStatus" required>
                                            @foreach ($statusMeta as $key => $meta)
                                            <option value="{{ $key }}">{{ $meta['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="update-arrived">Arrival <span class="font-normal text-outline">(opt.)</span></label>
                                        <input type="time" name="arrived_at" id="update-arrived" class="field w-full text-sm" x-model="arrived">
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
                history: {
                    present: 0,
                    late: 0,
                    absent: 0,
                    leave: 0,
                    total: 0,
                    rate: null,
                    recent: [],
                },
                pin: '',
                newStatus: 'present',
                remarks: '',
                arrived: '',
                openMark(payload) {
                    this.student = payload.student;
                    this.record = {};
                    this.history = payload.history || {
                        present: 0,
                        late: 0,
                        absent: 0,
                        leave: 0,
                        total: 0,
                        rate: null,
                        recent: [],
                    };
                    this.mode = 'mark';
                    this.open = true;
                },
                openUpdate(payload) {
                    this.student = payload.student;
                    this.record = payload.record || {};
                    this.pin = '';
                    this.newStatus = this.record.status || 'present';
                    this.remarks = this.record.remarks || '';
                    this.arrived = this.record.arrived || '';
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
                    } [status] || 'bg-surface-ice text-on-surface-variant';
                },
            };
        }
    </script>
</x-admin.layout>