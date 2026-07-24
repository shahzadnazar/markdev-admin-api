<x-admin.layout title="Daily attendance">
    @php
        $statusMeta = [
            'present' => ['label' => 'Present', 'badge' => 'success'],
            'late' => ['label' => 'Late', 'badge' => 'warning'],
            'absent' => ['label' => 'Absent', 'badge' => 'danger'],
            'leave' => ['label' => 'Leave', 'badge' => 'secondary'],
        ];
    @endphp

    <x-page-header eyebrow="Learning" title="Daily attendance"
        :description="'Register for '.$date->format('l, F j, Y').($date->isToday() ? ' — today' : '').'. Every active student, marked once; corrections need the security PIN and a reason.'">
        <x-slot:actions>
            @if ($counts['unmarked'] > 0)
                <x-confirm-form :action="route('admin.attendance.daily.bulk', ['date' => $date->toDateString()])" method="POST" variant="primary"
                    title="Mark remaining present?"
                    :message="$counts['unmarked'].' student(s) are still unmarked for '.$date->format('M j').'. Mark them all present now? Individual corrections stay possible via Update.'"
                    confirm-label="Mark all present"
                    class="inline-flex items-center justify-center gap-2 rounded-lg border border-primary/40 bg-white px-4 py-2.5 text-sm font-medium text-primary transition hover:border-primary hover:bg-primary/5">
                    <x-icon name="check" class="size-4" /> Mark remaining present
                </x-confirm-form>
            @endif
        </x-slot:actions>
    </x-page-header>

    @unless ($pinConfigured)
        <div class="mb-6 flex items-start gap-3 rounded-xl border border-warning/30 bg-warning/10 px-4 py-3.5 text-sm text-on-surface">
            <x-icon name="warning" class="mt-0.5 size-4.5 shrink-0 text-warning" />
            <p><span class="font-semibold">No attendance security PIN is set.</span> Marking works, but corrections stay locked until a Super Admin sets the PIN in System → Settings.</p>
        </div>
    @endunless

    {{-- Day summary --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-5">
        <x-stat-widget label="Present" :value="number_format($counts['present'])" icon="check" tone="success" />
        <x-stat-widget label="Late" :value="number_format($counts['late'])" icon="clock" tone="warning" />
        <x-stat-widget label="Absent" :value="number_format($counts['absent'])" icon="x-mark" tone="danger" />
        <x-stat-widget label="Leave" :value="number_format($counts['leave'])" icon="calendar" tone="secondary" />
        <x-stat-widget label="Not marked" :value="number_format($counts['unmarked'])" :sub="'of '.$counts['total'].' active students'"
            icon="clipboard" :tone="$counts['unmarked'] > 0 ? 'warning' : 'success'" />
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('admin.attendance.daily') }}" class="mb-6 flex flex-wrap items-end gap-3">
        <div>
            <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="date">Attendance date</label>
            <input type="date" name="date" id="date" value="{{ $date->toDateString() }}" max="{{ today()->toDateString() }}" class="field w-44">
        </div>
        <div>
            <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="status">Status</label>
            <select name="status" id="status" class="field w-40">
                <option value="">All</option>
                <option value="unmarked" @selected($statusFilter === 'unmarked')>Not marked</option>
                @foreach ($statusMeta as $key => $meta)
                    <option value="{{ $key }}" @selected($statusFilter === $key)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="min-w-56 flex-1">
            <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="search">Search</label>
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Name, reg #, CNIC…" class="field w-full">
        </div>
        <x-btn variant="secondary" size="md"><x-icon name="search" class="size-4" /> Load attendance</x-btn>
    </form>

    <div x-data="dailyAttendance()">
        <x-table>
            <thead class="bg-surface-ice/60">
                <tr>
                    <th class="th">Student</th>
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
                                'marked_by' => $record->marker?->name ?? ($record->source === 'biometric' ? 'Biometric device' : 'System'),
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
                                    <p class="truncate font-medium text-on-surface">{{ $student->name }}</p>
                                    <p class="truncate font-mono text-[11px] text-outline">{{ $student->studentProfile?->reg_no ?? $student->email }}</p>
                                </div>
                            </div>
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
                        <td class="td max-w-[14rem]">
                            <p class="truncate text-sm text-on-surface-variant" title="{{ $record?->remarks }}">{{ $record?->remarks ?? '—' }}</p>
                        </td>
                        <td class="td">
                            @if ($record)
                                <p class="font-mono text-xs text-on-surface">{{ $record->marked_at->format('g:i A') }}</p>
                                <p class="font-mono text-[11px] text-outline">{{ $record->marker?->name ?? ($record->source === 'biometric' ? 'Biometric' : '—') }}</p>
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
                                    <button type="button" x-on:click='openView(@json($payload))' title="View details"
                                        class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                        <x-icon name="eye" class="size-4" />
                                    </button>
                                    <button type="button" x-on:click='openUpdate(@json($payload))' title="Update (PIN required)"
                                        class="rounded-lg p-2 text-on-surface-variant transition hover:bg-warning/15 hover:text-warning">
                                        <x-icon name="pencil" class="size-4" />
                                    </button>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty-state icon="users" title="No students match"
                        description="Adjust the date, status or search filters." /></td></tr>
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

                <div x-show="open" x-transition class="relative w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-elevated">
                    {{-- Header --}}
                    <div class="flex items-center gap-3 border-b border-surface-ice px-6 py-4">
                        <template x-if="student.avatar">
                            <img :src="student.avatar" alt="" class="size-11 shrink-0 rounded-full object-cover">
                        </template>
                        <template x-if="! student.avatar">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary to-secondary font-display text-sm font-semibold text-white"
                                x-text="(student.name || '?').charAt(0).toUpperCase()"></span>
                        </template>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-display text-base font-semibold text-on-surface" x-text="student.name"></p>
                            <p class="font-mono text-[11px] text-outline">
                                <span x-text="student.reg || ''"></span> · {{ $date->format('D, M j, Y') }}
                            </p>
                        </div>
                        <button type="button" x-on:click="close()" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-surface-ice">
                            <x-icon name="x-mark" class="size-4" />
                        </button>
                    </div>

                    {{-- ---- View ---- --}}
                    <div x-show="mode === 'view'" class="space-y-4 px-6 py-5">
                        <div class="flex items-center gap-3">
                            <span class="font-mono text-[11px] uppercase tracking-wide text-on-surface-variant">Status</span>
                            <span class="rounded-full px-3 py-1 font-mono text-xs font-semibold uppercase"
                                :class="badgeClass(record.status)" x-text="record.status"></span>
                            <span class="font-mono text-[11px] text-outline" x-text="record.source === 'biometric' ? 'via biometric device' : 'marked manually'"></span>
                        </div>
                        <dl class="space-y-2.5 text-sm">
                            <div class="flex justify-between gap-4 border-b border-surface-ice pb-2">
                                <dt class="text-on-surface-variant">Marked</dt>
                                <dd class="text-right font-medium text-on-surface">
                                    <span x-text="record.marked_at"></span>
                                    <span class="block font-mono text-[11px] text-outline" x-text="'by ' + record.marked_by"></span>
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4 border-b border-surface-ice pb-2">
                                <dt class="text-on-surface-variant">Remarks</dt>
                                <dd class="text-right font-medium text-on-surface" x-text="record.remarks || '—'"></dd>
                            </div>
                            <template x-if="record.updated_at">
                                <div class="rounded-xl bg-warning/10 p-3.5">
                                    <p class="font-mono text-[11px] uppercase tracking-wide text-on-surface-variant">Last correction</p>
                                    <p class="mt-1 text-sm font-medium text-on-surface">
                                        <span x-text="record.updated_at"></span>
                                        <span class="font-normal text-on-surface-variant" x-text="' by ' + (record.updated_by || '—')"></span>
                                    </p>
                                    <p class="mt-1 text-sm text-on-surface-variant" x-text="'Reason: ' + (record.update_reason || '—')"></p>
                                </div>
                            </template>
                        </dl>
                        <div class="flex justify-end gap-3 pt-1">
                            <x-btn type="button" variant="ghost" x-on:click="close()">Close</x-btn>
                            @if ($pinConfigured)
                                <x-btn type="button" variant="secondary" x-on:click="mode = 'update'">
                                    <x-icon name="pencil" class="size-4" /> Update
                                </x-btn>
                            @endif
                        </div>
                    </div>

                    {{-- ---- Mark (first time — no PIN) ---- --}}
                    <form x-show="mode === 'mark'" method="POST" action="{{ route('admin.attendance.daily.mark') }}" class="space-y-5 px-6 py-5">
                        @csrf
                        <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                        <input type="hidden" name="user_id" :value="student.id">

                        <div>
                            <p class="mb-2 text-[13px] font-medium text-on-surface">Status</p>
                            <div class="grid grid-cols-4 gap-2">
                                @foreach ($statusMeta as $key => $meta)
                                    <label class="flex cursor-pointer items-center justify-center rounded-lg border px-2 py-2.5 text-sm font-medium transition
                                        has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:checked]:text-primary
                                        border-outline/30 text-on-surface-variant hover:border-outline/60">
                                        <input type="radio" name="status" value="{{ $key }}" class="sr-only" required @checked($key === 'present')>
                                        {{ $meta['label'] }}
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="mark-remarks">Remarks <span class="font-normal text-outline">(optional)</span></label>
                            <textarea name="remarks" id="mark-remarks" rows="2" maxlength="500" class="field w-full"
                                placeholder="e.g. informed leave, arrived late…"></textarea>
                        </div>

                        <p class="rounded-lg bg-surface-ice/70 px-3.5 py-2.5 font-mono text-[11px] text-on-surface-variant">
                            Will be saved as marked on {{ now()->format('D, M j · g:i A') }} by {{ auth()->user()->name }}.
                        </p>

                        <div class="flex justify-end gap-3">
                            <x-btn type="button" variant="ghost" x-on:click="close()">Cancel</x-btn>
                            <x-btn type="submit"><x-icon name="check" class="size-4" /> Save attendance</x-btn>
                        </div>
                    </form>

                    {{-- ---- Update (PIN + reason) ---- --}}
                    <form x-show="mode === 'update'" method="POST" :action="'{{ url('admin/attendance/daily') }}/' + (record.id || '')" class="space-y-5 px-6 py-5">
                        @csrf
                        @method('PUT')

                        <div class="rounded-xl border border-warning/30 bg-warning/10 p-4">
                            <label class="mb-1.5 flex items-center gap-2 text-[13px] font-medium text-on-surface" for="update-pin">
                                <x-icon name="shield" class="size-4 text-warning" /> Security PIN
                            </label>
                            <input type="password" name="pin" id="update-pin" x-model="pin" inputmode="numeric" autocomplete="one-time-code"
                                maxlength="8" class="field w-40 tracking-[0.3em]" placeholder="••••" required>
                            @error('pin')
                                <p class="mt-1.5 text-xs font-medium text-error">{{ $message }}</p>
                            @enderror
                            <p class="mt-1.5 text-xs text-on-surface-variant">Corrections are locked behind the academy PIN and always logged.</p>
                        </div>

                        <div x-show="pin.length >= 4" x-transition class="space-y-5">
                            <div>
                                <p class="mb-2 text-[13px] font-medium text-on-surface">New status</p>
                                <div class="grid grid-cols-4 gap-2">
                                    @foreach ($statusMeta as $key => $meta)
                                        <label class="flex cursor-pointer items-center justify-center rounded-lg border px-2 py-2.5 text-sm font-medium transition
                                            has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:checked]:text-primary
                                            border-outline/30 text-on-surface-variant hover:border-outline/60">
                                            <input type="radio" name="status" value="{{ $key }}" class="sr-only" x-model="newStatus">
                                            {{ $meta['label'] }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <div>
                                <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="update-remarks">Remarks <span class="font-normal text-outline">(optional)</span></label>
                                <textarea name="remarks" id="update-remarks" rows="2" maxlength="500" class="field w-full" x-model="remarks"></textarea>
                            </div>

                            <div>
                                <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="update-reason">
                                    Reason for this correction <span class="text-error">*</span>
                                </label>
                                <textarea name="reason" id="update-reason" rows="2" maxlength="500" class="field w-full" required minlength="3"
                                    placeholder="e.g. marked by mistake — student was on approved leave">{{ old('reason') }}</textarea>
                                @error('reason')
                                    <p class="mt-1.5 text-xs font-medium text-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <p class="rounded-lg bg-surface-ice/70 px-3.5 py-2.5 font-mono text-[11px] text-on-surface-variant">
                                Correction will be logged on {{ now()->format('D, M j · g:i A') }} by {{ auth()->user()->name }}.
                            </p>
                        </div>

                        <div class="flex justify-end gap-3">
                            <x-btn type="button" variant="ghost" x-on:click="close()">Cancel</x-btn>
                            <x-btn type="submit" x-bind:disabled="pin.length < 4">
                                <x-icon name="check" class="size-4" /> Verify PIN &amp; update
                            </x-btn>
                        </div>
                    </form>
                </div>
            </div>
        </template>
    </div>

    <script>
        function dailyAttendance() {
            return {
                open: false,
                mode: 'view',
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
                openView(payload) {
                    this.student = payload.student;
                    this.record = payload.record || {};
                    this.mode = 'view';
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
