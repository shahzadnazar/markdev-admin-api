<x-admin.layout :title="$announcement ? 'Edit announcement' : 'New announcement'">
    <x-page-header
        eyebrow="Engagement"
        :title="$announcement ? 'Edit announcement' : 'New announcement'"
        description="Pinned announcements stay at the top of every student's feed."
    >
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.announcements.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to announcements
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST"
        action="{{ $announcement ? route('admin.announcements.update', $announcement) : route('admin.announcements.store') }}"
        class="max-w-3xl">
        @csrf
        @if ($announcement) @method('PUT') @endif

        <x-card class="space-y-5">
            <x-form.input label="Title" name="title" :value="$announcement?->title" required />

            <x-form.textarea label="Body" name="body" :value="$announcement?->body" rows="8" required
                hint="Basic HTML is allowed (paragraphs, lists, links)." />

            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.select label="Audience" name="course_id" hint="Leave empty to reach every student.">
                    <option value="">Everyone</option>
                    @foreach ($courses as $course)
                        <option value="{{ $course->id }}" @selected(old('course_id', $announcement?->course_id) == $course->id)>
                            {{ $course->title }}
                        </option>
                    @endforeach
                </x-form.select>

                <x-form.input type="datetime-local" label="Publish at" name="published_at"
                    :value="old('published_at', optional($announcement?->published_at)->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i'))"
                    hint="Future dates keep it hidden until then." />
            </div>

            <x-form.toggle label="Pin to top" name="is_pinned" :checked="(bool) old('is_pinned', $announcement?->is_pinned)"
                hint="Pinned announcements always appear first." />
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn>
                <x-icon name="check" class="size-4" />
                {{ $announcement ? 'Save changes' : 'Publish announcement' }}
            </x-btn>
            <x-btn variant="ghost" :href="route('admin.announcements.index')">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
