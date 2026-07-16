<x-admin.layout title="Enroll a student">
    <x-page-header eyebrow="Learning" title="Enroll a student" description="Manually place a student into a course.">
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.enrollments.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to enrollments
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('admin.enrollments.store') }}" class="max-w-xl">
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

        <div class="mt-6 flex items-center gap-3">
            <x-btn>
                <x-icon name="check" class="size-4" /> Enroll student
            </x-btn>
            <x-btn variant="ghost" :href="route('admin.enrollments.index')">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
