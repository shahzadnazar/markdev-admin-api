<x-admin.layout :title="$quiz ? 'Edit quiz' : 'New quiz'">
    <x-page-header
        eyebrow="Learning"
        :title="$quiz ? 'Edit '.$quiz->title : 'New quiz'"
        description="Quiz rules and availability — questions are managed in the builder."
    >
        <x-slot:actions>
            @if ($quiz)
                <x-btn variant="secondary" :href="route('admin.quizzes.show', $quiz)">
                    <x-icon name="eye" class="size-4" /> Open builder
                </x-btn>
            @endif
            <x-btn variant="ghost" :href="route('admin.quizzes.index')">
                <x-icon name="arrow-left" class="size-4" /> Back
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    @php
        $lessonsByCourse = $courses->mapWithKeys(fn ($course) => [
            $course->id => $course->lessons->map(fn ($lesson) => ['id' => $lesson->id, 'title' => $lesson->title])->values(),
        ]);
    @endphp

    <form method="POST" action="{{ $quiz ? route('admin.quizzes.update', $quiz) : route('admin.quizzes.store') }}" class="max-w-3xl"
        x-data="{ course: '{{ old('course_id', $quiz?->course_id) }}', lessons: @js($lessonsByCourse), selected: '{{ old('lesson_id', $quiz?->lesson_id) }}' }">
        @csrf
        @if ($quiz) @method('PUT') @endif

        <x-card class="space-y-5">
            <p class="eyebrow">Quiz details</p>
            <x-form.input label="Title" name="title" :value="$quiz?->title" required />
            <x-form.textarea label="Description" name="description" :value="$quiz?->description" rows="2" />
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-form.label for="course_id" value="Course" />
                    <select name="course_id" id="course_id" class="field" x-model="course" required>
                        <option value="">Select a course…</option>
                        @foreach ($courses as $course)
                            <option value="{{ $course->id }}">{{ $course->title }}</option>
                        @endforeach
                    </select>
                    <x-form.error name="course_id" />
                </div>
                <div>
                    <x-form.label for="lesson_id" value="Linked lesson (optional)" />
                    <select name="lesson_id" id="lesson_id" class="field" x-model="selected" x-init="$nextTick(() => $el.value = selected)">
                        <option value="">None</option>
                        <template x-for="lesson in (lessons[course] ?? [])" :key="lesson.id">
                            <option :value="lesson.id" x-text="lesson.title" :selected="String(lesson.id) === String(selected)"></option>
                        </template>
                    </select>
                    <x-form.error name="lesson_id" />
                </div>
            </div>

            <p class="eyebrow pt-2">Rules</p>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-form.input label="Time limit (min)" name="time_limit_minutes" type="number" min="1" :value="$quiz?->time_limit_minutes" hint="Blank = untimed." />
                <x-form.input label="Attempts allowed" name="attempts_allowed" type="number" min="1" :value="$quiz?->attempts_allowed" hint="Blank = unlimited." />
                <x-form.input label="Passing score (%)" name="passing_score" type="number" min="0" max="100" :value="$quiz?->passing_score" />
            </div>

            <p class="eyebrow pt-2">Availability</p>
            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.input label="Available from" name="available_from" type="datetime-local" :value="old('available_from', optional($quiz?->available_from)->format('Y-m-d\TH:i'))" />
                <x-form.input label="Available until" name="available_until" type="datetime-local" :value="old('available_until', optional($quiz?->available_until)->format('Y-m-d\TH:i'))" />
            </div>
            <x-form.toggle label="Published" name="is_published" :checked="(bool) ($quiz?->is_published ?? false)" hint="Students can only take published quizzes." />
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn>
                <x-icon name="check" class="size-4" />
                {{ $quiz ? 'Save changes' : 'Create quiz' }}
            </x-btn>
            <x-btn variant="ghost" :href="$quiz ? route('admin.quizzes.show', $quiz) : route('admin.quizzes.index')">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
