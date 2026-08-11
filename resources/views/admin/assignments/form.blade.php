<x-admin.layout :title="$assignment ? 'Edit assignment' : 'New assignment'">
    <x-page-header
        eyebrow="Learning"
        :title="$assignment ? 'Edit '.$assignment->title : 'New assignment'"
        description="Define the brief, deadline and scoring."
    >
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.assignments.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to assignments
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    @php
        $lessonsByCourse = $courses->mapWithKeys(fn ($course) => [
            $course->id => $course->lessons->map(fn ($lesson) => ['id' => $lesson->id, 'title' => $lesson->title])->values(),
        ]);
    @endphp

    <form method="POST" action="{{ $assignment ? route('admin.assignments.update', $assignment) : route('admin.assignments.store') }}" enctype="multipart/form-data" class="max-w-3xl"
        x-data="{ course: '{{ old('course_id', $assignment?->course_id) }}', lessons: @js($lessonsByCourse), selected: '{{ old('lesson_id', $assignment?->lesson_id) }}' }">
        @csrf
        <x-form.errors-summary />
        @if ($assignment) @method('PUT') @endif

        <x-card class="space-y-5">
            <p class="eyebrow">Brief</p>
            <x-form.input label="Title" name="title" :value="$assignment?->title" required />
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-form.label for="course_id" value="Course" />
                    <select name="course_id" id="course_id" class="field" x-model="course" required>
                        <option value="">Select a course…</option>
                        @foreach ($courses as $course)
                            <option value="{{ $course->id }}" @selected((string) old('course_id', $assignment?->course_id) === (string) $course->id)>{{ $course->title }}</option>
                        @endforeach
                    </select>
                    <x-form.error name="course_id" />
                </div>
                <div>
                    <x-form.label for="lesson_id" value="Linked lesson (optional)" />
                    <select name="lesson_id" id="lesson_id" class="field" x-model="selected"
                        x-init="$nextTick(() => $el.value = selected)">
                        <option value="">None</option>
                        <template x-for="lesson in (lessons[course] ?? [])" :key="lesson.id">
                            <option :value="lesson.id" x-text="lesson.title" :selected="String(lesson.id) === String(selected)"></option>
                        </template>
                    </select>
                    <x-form.error name="lesson_id" />
                </div>
                <x-form.input label="Due date" name="due_at" type="datetime-local" :value="old('due_at', optional($assignment?->due_at)->format('Y-m-d\TH:i'))" />
                <x-form.input label="Max score" name="max_score" type="number" min="1" max="1000" :value="$assignment?->max_score ?? 100" required />
            </div>
            <x-form.textarea label="Short description" name="description" :value="$assignment?->description" rows="2" />
            <x-form.textarea label="Instructions" name="instructions" :value="$assignment?->instructions" rows="7" hint="HTML is allowed." />
        </x-card>

        <x-card class="mt-6 space-y-4">
            <p class="eyebrow">Attachments</p>
            @if ($assignment?->attachments?->isNotEmpty())
                <div class="space-y-2">
                    @foreach ($assignment->attachments as $attachment)
                        <div class="flex items-center gap-3 rounded-xl border border-outline-variant/60 px-4 py-2.5">
                            <x-icon name="document" class="size-4.5 shrink-0 text-outline" />
                            <a href="{{ $attachment->file_url }}" target="_blank" class="min-w-0 flex-1 truncate text-sm font-medium text-on-surface hover:text-primary">{{ $attachment->name }}</a>
                            <span class="font-mono text-[11px] text-outline">{{ \Illuminate\Support\Number::fileSize($attachment->size_bytes ?? 0) }}</span>
                            <x-confirm-form :action="route('admin.assignments.attachments.destroy', [$assignment, $attachment])" method="DELETE"
                                title="Remove attachment" :message="'Remove '.$attachment->name.'?'" confirm-label="Remove"
                                class="rounded-lg p-1.5 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                <x-icon name="trash" class="size-3.5" />
                            </x-confirm-form>
                        </div>
                    @endforeach
                </div>
            @endif
            <div>
                <input type="file" name="attachments[]" multiple
                    class="block w-full text-sm text-on-surface-variant file:mr-3 file:rounded-lg file:border-0 file:bg-primary/10 file:px-4 file:py-2.5 file:text-sm file:font-medium file:text-primary hover:file:bg-primary/15">
                <p class="mt-1.5 text-xs text-outline">Attach briefs, starter files or rubrics (max 20 MB each).</p>
                <x-form.error name="attachments.*" />
            </div>
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn>
                <x-icon name="check" class="size-4" />
                {{ $assignment ? 'Save changes' : 'Create assignment' }}
            </x-btn>
            <x-btn variant="ghost" :href="route('admin.assignments.index')">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
