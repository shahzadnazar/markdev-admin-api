<x-admin.layout :title="$note ? 'Edit Note' : 'Upload Note'">

    <x-page-header
        eyebrow="Learning"
        :title="$note ? 'Edit Note' : 'Upload Note'"
        :description="$note
            ? 'Update the note details or replace its file.'
            : 'Upload a document for students enrolled in a course.'"
    />

    <div class="max-w-3xl">

        <div class="rounded-xl border border-outline-variant bg-white p-6 shadow-sm">

            <form
                method="POST"
                enctype="multipart/form-data"
                action="{{ $note
                    ? route('admin.notes.update', $note)
                    : route('admin.notes.store') }}"
                class="space-y-6"
            >

                @csrf

                @if ($note)
                    @method('PUT')
                @endif

                {{-- Course --}}
                <div>
                    <x-form.label for="course_id" value="Course" />

                    <select
                        name="course_id"
                        id="course_id"
                        class="field mt-1 w-full"
                        required
                    >
                        <option value="">Select course</option>

                        @foreach ($courses as $course)
                            <option
                                value="{{ $course->id }}"
                                @selected(old('course_id', $note?->course_id) == $course->id)
                            >
                                {{ $course->title }}
                            </option>
                        @endforeach
                    </select>

                    @error('course_id')
                        <p class="mt-1 text-xs text-error">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Title --}}
                <div>
                    <x-form.label for="title" value="Note title" />

                    <input
                        type="text"
                        name="title"
                        id="title"
                        value="{{ old('title', $note?->title) }}"
                        placeholder="e.g. Chapter 1 Study Notes"
                        class="field mt-1 w-full"
                        required
                    >

                    @error('title')
                        <p class="mt-1 text-xs text-error">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Description --}}
                <div>
                    <x-form.label for="description" value="Description" />

                    <textarea
                        name="description"
                        id="description"
                        rows="4"
                        placeholder="Briefly describe this note..."
                        class="field mt-1 w-full"
                    >{{ old('description', $note?->description) }}</textarea>

                    @error('description')
                        <p class="mt-1 text-xs text-error">{{ $message }}</p>
                    @enderror
                </div>

                {{-- File --}}
                <div>
                    <x-form.label
                        for="file"
                        :value="$note ? 'Replace file' : 'File'"
                    />

                    <input
                        type="file"
                        name="file"
                        id="file"
                        class="field mt-1 w-full"
                        accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt"
                        @required(!$note)
                    >

                    <p class="mt-1 text-xs text-outline">
                        PDF, Word, PowerPoint, Excel or TXT. Maximum size: 20 MB.
                    </p>

                    @if ($note?->file_path)
                        <div class="mt-3 flex items-center gap-3 rounded-lg bg-surface-ice px-4 py-3">

                            <x-icon
                                name="document-text"
                                class="size-5 text-primary"
                            />

                            <div class="min-w-0">
                                <p class="text-sm font-medium text-on-surface">
                                    Current file
                                </p>

                                <p class="truncate text-xs text-outline">
                                    {{ basename($note->file_path) }}
                                </p>
                            </div>

                            <a
                                href="{{ route('admin.notes.download', $note) }}"
                                class="ml-auto text-xs font-semibold text-primary hover:underline"
                            >
                                Download
                            </a>

                        </div>
                    @endif

                    @error('file')
                        <p class="mt-1 text-xs text-error">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Actions --}}
                <div class="flex items-center justify-end gap-3 border-t border-surface-ice pt-5">

                    <x-btn
                        :href="route('admin.notes.index')"
                        variant="secondary"
                    >
                        Cancel
                    </x-btn>

                    <x-btn type="submit">
                        <x-icon
                            name="{{ $note ? 'check' : 'upload' }}"
                            class="size-4"
                        />

                        {{ $note ? 'Update note' : 'Upload note' }}
                    </x-btn>

                </div>

            </form>

        </div>

    </div>

</x-admin.layout>