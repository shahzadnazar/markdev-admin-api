<x-admin.layout title="Enroll students">
    <x-page-header title="Enroll students"
        description="Every active student — pick one, choose the course, and optionally split the fee into monthly installments."
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Enrollments' => route('admin.enrollments.index'), 'Enroll students' => null]">
        <x-slot:actions>
            <x-btn variant="ghost" size="sm" :href="route('admin.enrollments.index')">
                <x-icon name="arrow-left" class="size-4" /> Enrollments
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <div x-data="enrollPicker()" x-effect="if (feeOnly) withPlan = true">
        {{-- Toolbar: tabs + filters in one card --}}
        <x-card :padding="false" class="mb-4">
            <div class="flex flex-wrap items-center gap-2 px-4 py-2.5">
                @foreach (['all' => 'All active', 'unenrolled' => 'Not enrolled yet'] as $key => $label)
                    <a href="{{ route('admin.enrollments.create', array_filter(['tab' => $key === 'all' ? null : $key, 'course' => $courseId, 'search' => request('search')])) }}"
                        class="cursor-pointer rounded-lg border px-3 py-1.5 text-[13px] font-medium transition {{ $tab === $key
                            ? 'border-primary bg-primary text-white shadow-card'
                            : 'border-outline/30 bg-white text-on-surface-variant hover:border-primary/50 hover:text-primary' }}">
                        {{ $label }}
                    </a>
                @endforeach

                <form method="GET" action="{{ route('admin.enrollments.create') }}" class="ml-auto flex flex-wrap items-center gap-2">
                    @if ($tab === 'unenrolled')
                        <input type="hidden" name="tab" value="unenrolled">
                    @endif
                    <label class="sr-only" for="course">Filter by course</label>
                    <select name="course" id="course" class="field h-9 w-48 text-sm" title="Show students of this course">
                        <option value="">All courses</option>
                        @foreach ($courses as $course)
                            <option value="{{ $course->id }}" @selected($courseId === $course->id)>{{ $course->title }}</option>
                        @endforeach
                    </select>
                    <label class="sr-only" for="search">Search</label>
                    <input type="search" name="search" id="search" value="{{ request('search') }}"
                        placeholder="Name, reg #, CNIC…" class="field h-9 w-48 text-sm">
                    <x-btn variant="secondary" size="sm" class="h-9"><x-icon name="funnel" class="size-3.5" /> Apply</x-btn>
                    @if (request()->hasAny(['course', 'search']) || $tab === 'unenrolled')
                        <x-btn variant="ghost" size="sm" class="h-9" :href="route('admin.enrollments.create')">Clear</x-btn>
                    @endif
                </form>
            </div>
        </x-card>

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
                    {{ $students->links() }}
                </x-slot:footer>
            @endif
        </x-table>

        @if ($autoOpen && ! empty($autoPayload))
            {{-- Deep link (?enroll=&pick=): open the popup for this student right away. --}}
            <div x-init='openEnroll(@json($autoPayload), @json(request()->integer('pick') ?: null))'></div>
        @endif

        {{-- ···························· Enroll popup ···························· --}}
        <template x-teleport="body">
            <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
                x-on:keydown.escape.window="close()">
                <div x-show="open" x-transition.opacity class="absolute inset-0 bg-primary-deep/20 backdrop-blur-[2px]" x-on:click="close()"></div>

                <div x-show="open" x-transition class="relative flex max-h-[88vh] w-full max-w-md flex-col overflow-hidden rounded-2xl bg-white shadow-elevated">
                    <div class="flex shrink-0 items-center gap-3 border-b border-surface-ice px-5 py-3.5">
                        <template x-if="student.avatar">
                            <img :src="student.avatar" alt="" style="width: 2.5rem; height: 2.5rem;" class="shrink-0 rounded-full object-cover">
                        </template>
                        <template x-if="! student.avatar">
                            <span style="width: 2.5rem; height: 2.5rem;" class="flex shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary to-secondary font-display text-sm font-semibold text-white"
                                x-text="(student.name || '?').charAt(0).toUpperCase()"></span>
                        </template>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-display text-[15px] font-semibold text-on-surface" x-text="(feeOnly ? 'Add fee — ' : 'Enroll ') + student.name"></p>
                            <p class="font-mono text-[11px] text-outline" x-text="student.reg || ''"></p>
                        </div>
                        <button type="button" x-on:click="close()" class="cursor-pointer rounded-lg p-2 text-on-surface-variant transition hover:bg-surface-ice">
                            <x-icon name="x-mark" class="size-4" />
                        </button>
                    </div>

                    <form method="POST" action="{{ route('admin.enrollments.store') }}" class="min-h-0 space-y-4 overflow-y-auto px-5 py-4">
                        @csrf
                        <input type="hidden" name="user_id" :value="student.id">

                        <div>
                            <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="enroll-course">Course</label>
                            <select name="course_id" id="enroll-course" class="field w-full text-sm" x-model="courseId" required>
                                <option value="">Select a course…</option>
                                <template x-for="course in courses" :key="course.id">
                                    <option :value="course.id" :disabled="courseDisabled(course)" x-text="courseLabel(course)"></option>
                                </template>
                            </select>
                            <p x-show="feeOnly" x-cloak class="mt-1.5 rounded-lg bg-warning/10 px-3 py-2 text-xs leading-5 text-on-surface">
                                Already enrolled with no fee yet — this only generates the fee schedule, the enrollment is not duplicated.
                            </p>
                        </div>

                        {{-- Fee plan --}}
                        <div class="rounded-xl border border-primary/20 bg-primary/[0.03] p-4">
                            <label class="flex items-start gap-3" :class="feeOnly ? '' : 'cursor-pointer'">
                                <input type="hidden" name="create_plan" :value="feeOnly ? 1 : 0">
                                <input type="checkbox" name="create_plan" value="1" class="check mt-0.5" x-model="withPlan" :disabled="feeOnly">
                                <span>
                                    <span class="block text-sm font-medium text-on-surface">Generate the course fee</span>
                                    <span class="block text-xs text-on-surface-variant">Monthly installments or one full-payment invoice, from today's admission date.</span>
                                </span>
                            </label>

                            <div x-show="withPlan" x-transition x-cloak class="mt-4 space-y-4">
                                {{-- Payment mode --}}
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="choice-pill flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-outline/30 px-4 py-2.5 text-sm font-medium text-on-surface-variant transition hover:border-outline/60">
                                        <input type="radio" name="payment_mode" value="monthly" class="sr-only" :checked="mode === 'monthly'"
                                            x-on:change="mode = 'monthly'; months = 6">
                                        Monthly installments
                                    </label>
                                    <label class="choice-pill flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-outline/30 px-4 py-2.5 text-sm font-medium text-on-surface-variant transition hover:border-outline/60">
                                        <input type="radio" name="payment_mode" value="full" class="sr-only" :checked="mode === 'full'"
                                            x-on:change="mode = 'full'; months = 1">
                                        Full payment
                                    </label>
                                </div>

                                <div class="grid gap-3" :class="mode === 'monthly' ? 'grid-cols-3' : 'grid-cols-2'">
                                    <div>
                                        <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="enroll-total">Total fee (Rs)</label>
                                        <input type="number" name="total_fee" id="enroll-total" min="1" step="0.01" class="field w-full text-sm" x-model="total" :required="withPlan">
                                    </div>
                                    <div x-show="mode === 'monthly'">
                                        <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="enroll-months">Months</label>
                                        <input type="number" name="months" id="enroll-months" min="1" max="36" class="field w-full text-sm" x-model="months" :required="withPlan">
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="enroll-due">Due day</label>
                                        <input type="number" name="due_day" id="enroll-due" min="1" max="28" class="field w-full text-sm" x-model="dueDay" :required="withPlan">
                                    </div>
                                </div>

                                {{-- Admission charges: collected today at the counter --}}
                                <div class="rounded-lg border border-secondary/30 bg-secondary/[0.04] p-3">
                                    <p class="mb-2.5 font-mono text-[10px] font-semibold uppercase tracking-[0.08em] text-secondary">Admission charges — collected today</p>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="enroll-reg">Registration fee (Rs)</label>
                                            <input type="number" name="registration_fee" id="enroll-reg" min="0" step="0.01" class="field w-full text-sm" x-model="regFee">
                                            <p class="mt-1 text-[11px] text-outline">default from settings · 0 = waived</p>
                                        </div>
                                        <div x-show="mode === 'monthly'">
                                            <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="enroll-first">1st installment (Rs)</label>
                                            <input type="number" name="first_amount" id="enroll-first" min="1" step="0.01" class="field w-full text-sm"
                                                x-model="firstAmount" :placeholder="perMonth ? 'auto ' + perMonth.toLocaleString() : 'auto'">
                                            <p class="mt-1 text-[11px] text-outline">due today · blank = equal split</p>
                                        </div>
                                        <div x-show="mode === 'full'" class="flex items-end pb-5">
                                            <p class="text-[11px] leading-4 text-outline">Full fee is due today with the registration fee.</p>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-[13px] font-medium text-on-surface" for="enroll-fine">Late fine / day (Rs) <span class="font-normal text-outline">(optional)</span></label>
                                    <input type="number" name="fine_per_day" id="enroll-fine" min="0" step="0.01" class="field w-full text-sm"
                                        placeholder="default {{ number_format($defaultFinePerDay, 0) }}">
                                    <p class="mt-1.5 text-xs text-outline">Charged daily once the grace period ends. Blank = global setting.</p>
                                </div>
                                <input type="hidden" name="currency" value="PKR">

                                <p class="rounded-lg bg-white px-3 py-2 font-mono text-[11px] leading-5 text-on-surface-variant"
                                    x-show="preview" x-cloak x-text="preview"></p>
                            </div>
                        </div>

                        <div class="flex justify-end gap-2.5 pb-1">
                            <x-btn type="button" variant="ghost" size="sm" x-on:click="close()">Cancel</x-btn>
                            <x-btn type="submit" size="sm"><x-icon name="check" class="size-4" /> <span x-text="feeOnly ? 'Generate fee' : 'Enroll student'"></span></x-btn>
                        </div>
                    </form>
                </div>
            </div>
        </template>
    </div>

    <script>
        function enrollPicker() {
            return {
                open: false,
                student: {},
                courses: @json($courses->map(fn ($course) => ['id' => $course->id, 'title' => $course->title])->values()),
                withPlan: false,
                mode: 'monthly',
                courseId: '',
                total: '',
                months: 6,
                dueDay: 5,
                regFee: @js((float) $defaultRegistrationFee),
                firstAmount: '',
                openEnroll(student, pick = null) {
                    this.student = student;
                    this.courseId = '';
                    this.mode = 'monthly';
                    this.withPlan = false;
                    this.total = '';
                    this.months = 6;
                    this.regFee = @js((float) $defaultRegistrationFee);
                    this.firstAmount = '';
                    this.open = true;
                    // Deferred: on deep links this runs mid-init, before the
                    // select's options are stamped — sync the value one tick later.
                    if (pick) this.$nextTick(() => { this.courseId = String(pick); });
                },
                close() {
                    this.open = false;
                },
                isEnrolled(id) {
                    return (this.student.enrolled || []).includes(id);
                },
                hasPlan(id) {
                    return (this.student.planned || []).includes(id);
                },
                // Selected course is already enrolled but has no fee yet → only the fee gets created.
                get feeOnly() {
                    const id = parseInt(this.courseId);
                    return !!id && this.isEnrolled(id) && ! this.hasPlan(id);
                },
                courseLabel(course) {
                    if (! this.isEnrolled(course.id)) return course.title;
                    return course.title + (this.hasPlan(course.id) ? ' — already enrolled' : ' — enrolled · fee not generated');
                },
                courseDisabled(course) {
                    return this.isEnrolled(course.id) && this.hasPlan(course.id);
                },
                get perMonth() {
                    const t = parseFloat(this.total), m = parseInt(this.months);
                    if (!t || !m || m < 1) return null;
                    return Math.floor(t / m * 100) / 100;
                },
                get lastMonth() {
                    const t = parseFloat(this.total), m = parseInt(this.months);
                    if (!t || !m || m < 1 || !this.perMonth) return null;
                    return Math.round((t - this.perMonth * (m - 1)) * 100) / 100;
                },
                get firstDue() {
                    const day = parseInt(this.dueDay);
                    if (!day || day < 1 || day > 28) return null;
                    const now = new Date();
                    let due = new Date(now.getFullYear(), now.getMonth(), day);
                    if (due < new Date(now.getFullYear(), now.getMonth(), now.getDate())) {
                        due = new Date(now.getFullYear(), now.getMonth() + 1, day);
                    }
                    return due.toLocaleDateString('en', { month: 'short', day: 'numeric', year: 'numeric' });
                },
                get preview() {
                    const t = parseFloat(this.total), reg = parseFloat(this.regFee) || 0;
                    if (!t) return null;

                    if (this.mode === 'full') {
                        return 'Collect today: Rs ' + (reg + t).toLocaleString()
                            + ' — ' + (reg > 0 ? 'registration ' + reg.toLocaleString() + ' + ' : '')
                            + 'full fee ' + t.toLocaleString();
                    }

                    const m = parseInt(this.months);
                    if (!m || m < 1) return null;
                    const first = (m > 1 && parseFloat(this.firstAmount)) ? parseFloat(this.firstAmount) : this.perMonth;
                    if (!first || first >= t) return null;
                    const rest = m - 1;
                    const rem = rest > 0 ? Math.floor((t - first) / rest * 100) / 100 : 0;

                    return 'Collect today: Rs ' + (reg + first).toLocaleString()
                        + ' — ' + (reg > 0 ? 'registration ' + reg.toLocaleString() + ' + ' : '')
                        + 'installment 1 (advance) ' + first.toLocaleString()
                        + (rest > 0 ? ' · then ' + rest + ' × Rs ' + rem.toLocaleString() + ' due day ' + this.dueDay : '')
                        + ' · opens {{ \App\Support\BillingConfig::activationDays() }} days before each due date';
                },
            };
        }
    </script>
</x-admin.layout>
