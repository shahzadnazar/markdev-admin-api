<x-admin.layout title="Enroll a student">
    <x-page-header eyebrow="Learning" title="Enroll a student"
        description="Place a student into a course — optionally with a monthly installment plan.">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.enrollments.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to enrollments
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('admin.enrollments.store') }}" class="max-w-2xl"
        x-data="{
            withPlan: @js((bool) old('create_plan')),
            total: @js(old('total_fee', '')),
            months: @js(old('months', 6)),
            dueDay: @js(old('due_day', 5)),
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
        }">
        @csrf

        <x-card class="space-y-5">
            <x-form.select label="Student" name="user_id" required>
                <option value="">Select a student…</option>
                @foreach ($students as $student)
                    <option value="{{ $student->id }}" @selected(old('user_id') == $student->id)>{{ $student->name }} — {{ $student->email }}</option>
                @endforeach
            </x-form.select>
            <x-form.select label="Course" name="course_id" required>
                <option value="">Select a course…</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected(old('course_id') == $course->id)>{{ $course->title }}</option>
                @endforeach
            </x-form.select>
        </x-card>

        {{-- Installment plan --}}
        <x-card class="mt-6 space-y-5">
            <label class="flex cursor-pointer items-start gap-3">
                <input type="hidden" name="create_plan" value="0">
                <input type="checkbox" name="create_plan" value="1" class="check mt-0.5" x-model="withPlan">
                <span>
                    <span class="block text-[13px] font-medium text-on-surface">Create a monthly installment plan</span>
                    <span class="mt-0.5 block text-xs text-outline">Splits the total fee into monthly invoices starting from today's admission date.</span>
                </span>
            </label>

            <div x-show="withPlan" x-cloak class="space-y-5 border-t border-surface-ice pt-5">
                <div class="grid gap-5 sm:grid-cols-3">
                    <x-form.input type="number" label="Total fee" name="total_fee" :value="old('total_fee')"
                        min="1" step="0.01" x-model="total" placeholder="45000" />
                    <x-form.input type="number" label="Duration (months)" name="months" :value="old('months', 6)"
                        min="1" max="36" x-model="months" />
                    <x-form.input type="number" label="Due day of month" name="due_day" :value="old('due_day', 5)"
                        min="1" max="28" x-model="dueDay" hint="e.g. 5 = due on the 5th." />
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-form.input type="number" label="Defaulter fine / day" name="fine_per_day"
                        :value="old('fine_per_day')" min="0" step="0.01"
                        :placeholder="number_format($defaultFinePerDay, 0)"
                        hint="Leave blank to use the global setting ({{ number_format($defaultFinePerDay, 0) }}/day)." />
                    <x-form.input label="Currency" name="currency" :value="old('currency', 'PKR')" maxlength="3"
                        hint="ISO code, e.g. PKR." />
                </div>

                {{-- Live preview --}}
                <div class="rounded-xl bg-surface-ice/70 p-4" x-show="perMonth" x-cloak>
                    <p class="eyebrow mb-2">Schedule preview</p>
                    <p class="text-sm text-on-surface-variant">
                        <span class="font-semibold text-on-surface" x-text="months"></span> installments of
                        <span class="font-mono font-semibold text-primary" x-text="perMonth?.toLocaleString()"></span>
                        <template x-if="lastMonth !== perMonth">
                            <span>(last <span class="font-mono" x-text="lastMonth?.toLocaleString()"></span>)</span>
                        </template>
                        — first due <span class="font-semibold text-on-surface" x-text="firstDue"></span>,
                        then the <span class="font-semibold text-on-surface" x-text="dueDay"></span><sup>th</sup> of every month.
                        Each installment opens for payment {{ \App\Support\BillingConfig::activationDays() }} days before it is due.
                    </p>
                </div>
            </div>
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn>
                <x-icon name="check" class="size-4" /> Enroll student
            </x-btn>
            <x-btn variant="ghost" :href="route('admin.enrollments.index')">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
