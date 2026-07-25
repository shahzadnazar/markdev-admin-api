<x-admin.layout :title="$plan ? 'Edit fee plan' : 'New fee plan'">
    <x-page-header
        eyebrow="Finance"
        :title="$plan ? 'Edit fee plan' : 'New fee plan'"
        description="A fee plan defines what a student owes; invoices are issued against it."
    >
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.billing.plans.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to fee plans
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST"
        action="{{ $plan ? route('admin.billing.plans.update', $plan) : route('admin.billing.plans.store') }}"
        class="max-w-2xl">
        @csrf
        @if ($plan) @method('PUT') @endif


        <x-form.errors-summary />
        <x-card class="space-y-5">
            <x-form.select label="Student" name="user_id" required>
                <option value="">Choose a student…</option>
                @foreach ($students as $student)
                    <option value="{{ $student->id }}" @selected(old('user_id', $plan?->user_id) == $student->id)>
                        {{ $student->name }} — {{ $student->email }}
                    </option>
                @endforeach
            </x-form.select>

            <x-form.select label="Course (optional)" name="course_id" hint="Ties the plan to a specific course.">
                <option value="">No specific course</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected(old('course_id', $plan?->course_id) == $course->id)>{{ $course->title }}</option>
                @endforeach
            </x-form.select>

            <x-form.input label="Plan title" name="title" :value="$plan?->title" required
                hint="Shown on the student's Payments page, e.g. “Advanced Web Development”." />

            <div class="grid gap-5 sm:grid-cols-3">
                <x-form.select label="Billing cycle" name="billing_cycle" required>
                    @foreach (['one_time' => 'One-time', 'monthly' => 'Monthly', 'annual' => 'Annual'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('billing_cycle', $plan?->billing_cycle) === $value)>{{ $label }}</option>
                    @endforeach
                </x-form.select>
                <x-form.input label="Currency" name="currency" :value="$plan?->currency ?? 'USD'" required hint="ISO code, e.g. USD." />
                <x-form.input type="number" label="Total amount" name="total_amount" :value="$plan?->total_amount" required step="0.01" min="0" />
            </div>

            <x-form.toggle label="Plan is active" name="is_active" :checked="(bool) old('is_active', $plan?->is_active ?? true)"
                hint="Inactive plans stop appearing on the student's billing overview." />
        </x-card>

        <x-form.actions :cancel="route('admin.billing.plans.index')">
            <x-btn>
                <x-icon name="check" class="size-4" />
                {{ $plan ? 'Save changes' : 'Create fee plan' }}
            </x-btn>
        </x-form.actions>
    </form>
</x-admin.layout>
