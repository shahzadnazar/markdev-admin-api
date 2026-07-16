<x-admin.layout title="Issue certificate">
    <x-page-header
        eyebrow="Learning"
        title="Issue certificate"
        description="Manually award a certificate — the student must be enrolled in the course."
    >
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.certificates.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to certificates
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('admin.certificates.store') }}" class="max-w-2xl">
        @csrf

        <x-card class="space-y-5">
            <x-form.select label="Student" name="user_id" required>
                <option value="">Choose a student…</option>
                @foreach ($students as $student)
                    <option value="{{ $student->id }}" @selected(old('user_id') == $student->id)>
                        {{ $student->name }} — {{ $student->email }}
                    </option>
                @endforeach
            </x-form.select>

            <x-form.select label="Course" name="course_id" required>
                <option value="">Choose a course…</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected(old('course_id') == $course->id)>{{ $course->title }}</option>
                @endforeach
            </x-form.select>
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn>
                <x-icon name="certificate" class="size-4" /> Issue certificate
            </x-btn>
            <x-btn variant="ghost" :href="route('admin.certificates.index')">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
