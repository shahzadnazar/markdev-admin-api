<x-admin.layout :title="$student ? 'Edit student' : 'Register student'">
    <x-page-header eyebrow="People"
        :title="$student ? 'Edit '.$student->name : 'Student registration form'"
        :description="$student ? 'Update the admission record, documents and account.' : 'New admission — mirrors the printed MarkDev registration form.'">
        <x-slot:actions>
            <x-btn variant="ghost" :href="$student ? route('admin.students.show', $student) : route('admin.students.index')">
                <x-icon name="arrow-left" class="size-4" /> {{ $student ? 'Back to profile' : 'All students' }}
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" enctype="multipart/form-data"
        action="{{ $student ? route('admin.students.update', $student) : route('admin.students.store') }}"
        x-data="{ plan: {{ old('create_plan') ? 'true' : 'false' }} }">
        @csrf
        @if ($student) @method('PUT') @endif


        <x-form.errors-summary />
        <div class="grid items-start gap-6 xl:grid-cols-[1fr_24rem]">
            <div class="space-y-6">
                {{-- ····························· Personal information ····························· --}}
                <x-card :padding="false">
                    <div class="flex items-center gap-3 rounded-t-2xl border-l-4 border-primary-deep bg-primary px-6 py-3.5">
                        <x-icon name="user-circle" class="size-5 text-white/80" />
                        <h2 class="font-display text-sm font-bold uppercase tracking-[0.08em] text-white">Personal information</h2>
                        <span class="ml-auto rounded-full bg-white/15 px-3 py-1 font-mono text-[11px] text-white">Reg # {{ $nextRegNo }}</span>
                    </div>
                    <div class="space-y-5 p-6">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-form.input label="Full name" name="name" :value="$student?->name" required />
                            <x-form.input label="Father name" name="father_name" :value="$profile?->father_name" required />
                        </div>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-form.input type="date" label="Date of birth" name="date_of_birth"
                                :value="optional($profile?->date_of_birth)->format('Y-m-d')" required :max="now()->toDateString()" />
                            <div>
                                <x-form.label for="gender" value="Gender" />
                                <div class="mt-1 grid grid-cols-2 gap-2">
                                    @foreach (['male' => 'Male', 'female' => 'Female'] as $value => $label)
                                        <label class="choice-pill flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-outline/30 px-4 py-2.5 text-sm font-medium text-on-surface-variant transition hover:border-outline/60">
                                            <input type="radio" name="gender" value="{{ $value }}" class="sr-only"
                                                @checked(old('gender', $profile?->gender) === $value) required>
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                                <x-form.error name="gender" />
                            </div>
                        </div>
                        <x-form.input label="Address" name="address" :value="$profile?->address" required />
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-form.input label="Contact number" name="contact_number" :value="$student?->phone" required placeholder="03xx xxxxxxx" />
                            <x-form.input label="CNIC / B-Form" name="cnic" :value="$profile?->cnic" required placeholder="xxxxx-xxxxxxx-x" />
                        </div>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-form.input label="Guardian contact" name="guardian_contact" :value="$profile?->guardian_contact" />
                            <x-form.input label="Current qualification" name="current_qualification" :value="$profile?->current_qualification" required />
                        </div>
                        @php
                            // The column stores the course title, so the options are titles.
                            // A profile saved before this was a dropdown may hold something
                            // that is no longer in the catalogue — keep it as an option so
                            // editing a student never silently rewrites their record.
                            $appliedCourse = old('applied_course', $profile?->applied_course);
                            $courseTitles = $courses->pluck('title');
                            $extraApplied = $appliedCourse && ! $courseTitles->contains($appliedCourse)
                                ? $appliedCourse
                                : null;
                        @endphp
                        <x-form.select label="Applied course" name="applied_course" required
                            hint="What the student applied for. The actual enrollment is set under Office use.">
                            <option value="">— Select a course —</option>
                            @foreach ($courseTitles as $title)
                                <option value="{{ $title }}" @selected($appliedCourse === $title)>{{ $title }}</option>
                            @endforeach
                            @if ($extraApplied)
                                <option value="{{ $extraApplied }}" selected>{{ $extraApplied }}</option>
                            @endif
                        </x-form.select>
                    </div>
                </x-card>

                {{-- ··························· Emergency contact details ··························· --}}
                <x-card :padding="false">
                    <div class="flex items-center gap-3 rounded-t-2xl border-l-4 border-primary-deep bg-primary px-6 py-3.5">
                        <x-icon name="lifebuoy" class="size-5 text-white/80" />
                        <h2 class="font-display text-sm font-bold uppercase tracking-[0.08em] text-white">Emergency contact details</h2>
                    </div>
                    <div class="grid gap-5 p-6 sm:grid-cols-2">
                        <x-form.input label="Name" name="emergency_name" :value="$profile?->emergency_name" required />
                        <x-form.input label="Contact number" name="emergency_contact" :value="$profile?->emergency_contact" required />
                        <x-form.input label="Relation" name="emergency_relation" :value="$profile?->emergency_relation" required placeholder="Father, mother, brother…" />
                        <x-form.input label="Residence" name="emergency_residence" :value="$profile?->emergency_residence" />
                    </div>
                </x-card>

                {{-- ································· Office use only ································ --}}
                <x-card :padding="false">
                    <div class="flex items-center gap-3 rounded-t-2xl border-l-4 border-primary-deep bg-primary px-6 py-3.5">
                        <x-icon name="banknotes" class="size-5 text-white/80" />
                        <h2 class="font-display text-sm font-bold uppercase tracking-[0.08em] text-white">Office use only</h2>
                    </div>
                    <div class="space-y-5 p-6">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-form.input type="date" label="Date of joining" name="date_of_joining"
                                :value="optional($profile?->date_of_joining)->format('Y-m-d') ?? now()->toDateString()" required />
                                <x-form.input
                                label="Batch No"
                                name="batch_no"
                                :value="$profile?->batch_no"
                                placeholder="e.g. Batch 01"
                                hint="Assigned by office/admin only." />
                            <x-form.select label="Course enrollment" name="course_id"
                                :hint="$student ? 'Enrollments are managed from the Enrollments screen after registration.' : 'Optional — enrolls the student right away.'"
                                :disabled="(bool) $student">
                                <option value="">— No enrollment yet —</option>
                                @foreach ($courses as $course)
                                    <option value="{{ $course->id }}" @selected(old('course_id') == $course->id)>{{ $course->title }}</option>
                                @endforeach
                            </x-form.select>
                        </div>
                        <div class="grid gap-5 sm:grid-cols-3">
                            <x-form.input type="number" label="Total fee (Rs)" name="total_fee" :value="$profile?->total_fee" min="0" step="0.01" class="no-spinner" />
                            <x-form.input type="number" label="Submitted fee (Rs)" name="submitted_fee" :value="$profile?->submitted_fee" min="0" step="0.01" class="no-spinner" />
                            <x-form.input type="number" label="Registration fee (Rs)" name="registration_fee" :value="$profile?->registration_fee ?? $defaultRegistrationFee" min="0" step="0.01" class="no-spinner"
                                hint="Collected today at admission — becomes its own invoice when a fee plan is generated. 0 = waived." />
                        </div>
                        <x-form.input label="Reference" name="reference" :value="$profile?->reference" hint="Who referred this student, if anyone." />

                        @unless ($student)
                            <div class="rounded-xl border border-primary/20 bg-primary/[0.03] p-4">
                                <label class="flex cursor-pointer items-start gap-3">
                                    <input type="checkbox" name="create_plan" value="1" x-model="plan"
                                        class="mt-0.5 size-4 rounded border-outline/40 text-primary focus:ring-primary/30">
                                    <span>
                                        <span class="block text-sm font-medium text-on-surface">Create monthly installment plan</span>
                                        <span class="block text-xs text-on-surface-variant">Splits the total fee into monthly invoices for the selected course (requires a course enrollment).</span>
                                    </span>
                                </label>
                                <div x-show="plan" x-cloak class="mt-4 grid gap-5 sm:grid-cols-3">
                                    <x-form.input type="number" label="Months" name="months" :value="old('months')" min="1" max="36" />
                                    <x-form.input type="number" label="Due day of month" name="due_day" :value="old('due_day', 5)" min="1" max="28" />
                                    <x-form.input type="number" label="Late fine / day (Rs)" name="fine_per_day" :value="old('fine_per_day')"
                                        min="0" step="0.01" :placeholder="'default '.number_format($defaultFinePerDay)" />
                                </div>
                                <div x-show="plan" x-cloak class="mt-4">
                                    <x-form.input type="number" label="1st installment — advance (Rs)" name="first_amount" :value="old('first_amount')"
                                        min="1" step="0.01" placeholder="blank = equal split"
                                        hint="Due today with the registration fee; the remaining fee divides equally over the remaining months." />
                                </div>
                            </div>
                        @endunless
                    </div>
                </x-card>

                {{-- ······························ Terms and conditions ····························· --}}
                @unless ($student)
                    <x-card :padding="false">
                        <div class="flex items-center gap-3 rounded-t-2xl border-l-4 border-primary-deep bg-primary px-6 py-3.5">
                            <x-icon name="shield" class="size-5 text-white/80" />
                            <h2 class="font-display text-sm font-bold uppercase tracking-[0.08em] text-white">Terms and conditions</h2>
                        </div>
                        <div class="p-6">
                            <p class="text-sm font-semibold text-on-surface">I hereby agree to the following terms and conditions set by MarkDev:</p>
                            <ul class="mt-3 list-disc space-y-1.5 pl-5 text-sm leading-6 text-on-surface-variant">
                                <li>I understand that course fees are non-refundable under any circumstances.</li>
                                <li>I will follow all rules, discipline, and professional ethics during the training.</li>
                                <li>I understand that certificates will be awarded after successful course completion.</li>
                                <li>I will respect instructors, staff, and fellow trainees at all times.</li>
                            </ul>
                            <p class="mt-3 text-sm font-semibold text-on-surface">
                                MarkDev reserves the right to suspend or dismiss any student for misconduct or violation of these terms.
                            </p>
                            <label class="mt-5 flex cursor-pointer items-start gap-3 rounded-xl border border-outline/20 bg-surface-ice/50 p-4">
                                <input type="checkbox" name="terms" value="1" required @checked(old('terms'))
                                    class="mt-0.5 size-4 rounded border-outline/40 text-primary focus:ring-primary/30">
                                <span class="text-sm text-on-surface">The student has read, understood and <span class="font-semibold">signed</span> the terms &amp; conditions on the registration form.</span>
                            </label>
                            <x-form.error name="terms" />
                        </div>
                    </x-card>
                @endunless
            </div>

            {{-- ································ Side column ································ --}}
            <div class="space-y-6">
                {{-- Portal account --}}
                <x-card>
                    <div class="mb-4 flex items-center gap-2.5">
                        <x-icon name="user-circle" class="size-4 text-primary" />
                        <h2 class="font-mono text-label-md uppercase text-on-surface">Portal account</h2>
                    </div>
                    <div class="space-y-5">
                        <x-form.input type="email" label="Email (portal login)" name="email" :value="$student?->email" required />
                        <x-form.input type="password" label="Password" name="password"
                            :hint="$student ? 'Leave blank to keep the current password.' : 'Leave blank to auto-generate — it is shown once after saving.'" />
                        @if ($student)
                            <x-form.toggle label="Account active" name="is_active" :checked="(bool) old('is_active', $student->is_active)"
                                hint="Inactive students cannot sign in to the portal." />
                        @endif
                    </div>
                </x-card>

                {{-- Documents --}}
                <x-card>
                    <div class="mb-1 flex items-center gap-2.5">
                        <x-icon name="document" class="size-4 text-primary" />
                        <h2 class="font-mono text-label-md uppercase text-on-surface">Documents</h2>
                    </div>
                    <p class="mb-4 text-xs text-outline">JPG, PNG, WEBP{{ '' }} or PDF · max 1 MB each</p>

                    <div class="space-y-4">
                        <x-students.doc-field name="photo" label="Profile picture" accept="image/jpeg,image/png,image/webp"
                            :required="! $student" :existing="$profile?->photo_path" kind="image" />
                        <x-students.doc-field name="cnic_doc" label="CNIC / B-Form copy" accept="image/jpeg,image/png,image/webp,application/pdf"
                            :required="! $student" :existing="$profile?->cnic_doc_path" kind="any" />
                        <x-students.doc-field name="degree_doc" label="Last degree / certificate" accept="image/jpeg,image/png,image/webp,application/pdf"
                            :required="! $student" :existing="$profile?->degree_doc_path" kind="any" />
                    </div>
                </x-card>

                <div class="flex flex-col gap-3">
                    <x-btn class="justify-center">
                        <x-icon name="check" class="size-4" />
                        {{ $student ? 'Save changes' : 'Register student' }}
                    </x-btn>
                    <x-btn variant="ghost" class="justify-center"
                        :href="$student ? route('admin.students.show', $student) : route('admin.students.index')">Cancel</x-btn>
                </div>
            </div>
        </div>
    </form>
</x-admin.layout>
