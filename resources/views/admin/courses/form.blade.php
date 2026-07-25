<x-admin.layout :title="$course ? 'Edit course' : 'New course'">
    <x-page-header
        eyebrow="Learning"
        :title="$course ? 'Edit '.$course->title : 'New course'"
        :description="$course ? 'Update the course details and publishing state.' : 'Set up the course shell — you will build the curriculum next.'"
    >
        <x-slot:actions>
            @if ($course)
                <x-btn variant="secondary" :href="route('admin.courses.show', $course)">
                    <x-icon name="eye" class="size-4" /> Open builder
                </x-btn>
            @endif
            <x-btn variant="ghost" :href="route('admin.courses.index')">
                <x-icon name="arrow-left" class="size-4" /> Back
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ $course ? route('admin.courses.update', $course) : route('admin.courses.store') }}" enctype="multipart/form-data" class="max-w-4xl" x-data="{ free: {{ old('is_free', $course?->is_free ?? false) ? 'true' : 'false' }} }">
        @csrf
        <x-form.errors-summary />
        @if ($course) @method('PUT') @endif

        <div class="grid gap-6">
            <x-card class="space-y-5">
                <p class="eyebrow">Course details</p>
                <x-form.input label="Title" name="title" :value="$course?->title" required />
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-form.input label="Slug" name="slug" :value="$course?->slug"
                        hint="The course's web address, e.g. /courses/web-development. Leave blank to create it from the title automatically." />
                    <div>
                        <x-form.select label="Category" name="category_id" required>
                            <option value="">Select a category…</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected(old('category_id', $course?->category_id) == $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </x-form.select>
                        @can('categories.create')
                            <p class="mt-1.5 text-xs text-outline">Missing one? <a href="{{ route('admin.categories.index') }}" class="font-medium text-primary hover:underline" target="_blank">Add it in Categories</a> — web, marketing, AI, graphics, apps…</p>
                        @endcan
                    </div>
                    <x-form.input label="Duration" name="duration_label" :value="$course?->duration_label"
                        placeholder="e.g. 3 months, 12 weeks" hint="Program length shown to students and on the course list." />
                    <x-form.select label="Instructor" name="instructor_id" required hint="Users holding the instructor role.">
                        <option value="">Select an instructor…</option>
                        @foreach ($instructors as $instructor)
                            <option value="{{ $instructor->id }}" @selected(old('instructor_id', $course?->instructor_id) == $instructor->id)>{{ $instructor->name }}</option>
                        @endforeach
                    </x-form.select>
                    <x-form.select label="Level" name="level" required>
                        @foreach (['beginner', 'intermediate', 'advanced'] as $level)
                            <option value="{{ $level }}" @selected(old('level', $course?->level ?? 'beginner') === $level)>{{ ucfirst($level) }}</option>
                        @endforeach
                    </x-form.select>
                </div>
                <x-form.textarea label="Short description" name="excerpt" :value="$course?->excerpt" rows="2" hint="One or two lines shown on the course cards in the student portal." />
                <x-form.textarea label="Description" name="description" :value="$course?->description" rows="7" hint="HTML is allowed." />
                <x-form.input label="Tags" name="tags" :value="is_array($course?->tags) ? implode(', ', $course->tags) : ''" hint="Comma separated, e.g. laravel, api, backend." />
            </x-card>

            <x-card class="space-y-5">
                <p class="eyebrow">Media &amp; fee</p>
                <div class="grid items-start gap-5 sm:grid-cols-2">
                    <div>
                        <x-form.label for="thumbnail" value="Thumbnail" />
                        @if ($course?->thumbnail_path)
                            <img src="{{ $course->thumbnail_url }}" alt="Current thumbnail" class="mb-3 h-28 w-full rounded-xl object-cover">
                        @endif
                        <input type="file" name="thumbnail" id="thumbnail" accept="image/*"
                            class="block w-full text-sm text-on-surface-variant file:mr-3 file:rounded-lg file:border-0 file:bg-primary/10 file:px-4 file:py-2.5 file:text-sm file:font-medium file:text-primary hover:file:bg-primary/15">
                        <p class="mt-1.5 text-xs text-outline">PNG or JPG, up to 4 MB. Stored on the public disk under courses/.</p>
                        <x-form.error name="thumbnail" />
                    </div>
                    <div class="space-y-5">
                        <x-form.toggle label="Free course" name="is_free" :checked="(bool) ($course?->is_free ?? false)" hint="Free courses skip billing entirely." x-model="free" />
                        <div x-show="! free" x-cloak>
                            <x-form.input label="Fee (Rs)" name="price" type="number" step="0.01" min="0" :value="$course?->price"
                                hint="Course fee in rupees. Monthly installments are set up at enrollment." />
                        </div>
                    </div>
                </div>
            </x-card>

            <x-card class="space-y-5">
                <p class="eyebrow">Publishing</p>
                <div class="max-w-xs">
                    <x-form.select label="Status" name="status" required>
                        @foreach (['draft', 'published', 'archived'] as $status)
                            <option value="{{ $status }}" @selected(old('status', $course?->status ?? 'draft') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-form.select>
                </div>
            </x-card>
        </div>

        <x-form.actions :cancel="route('admin.courses.index')">
            <x-btn>
                <x-icon name="check" class="size-4" />
                {{ $course ? 'Save changes' : 'Create course' }}
            </x-btn>
        </x-form.actions>
    </form>
</x-admin.layout>
