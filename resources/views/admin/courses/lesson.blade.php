<x-admin.layout :title="'Edit lesson — '.$lesson->title">
    <x-page-header
        eyebrow="Course builder"
        :title="$lesson->title"
        :description="'Module “'.$lesson->module?->title.'” · '.$lesson->course?->title"
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Courses' => route('admin.courses.index'), ($lesson->course?->title ?? 'Course') => route('admin.courses.show', $lesson->course_id), $lesson->title => null]"
    >
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.courses.show', $lesson->course_id)">
                <x-icon name="arrow-left" class="size-4" /> Back to builder
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <div class="grid max-w-5xl gap-6 xl:grid-cols-3">
        <form method="POST" action="{{ route('admin.lessons.update', $lesson) }}" class="xl:col-span-2"
            x-data="{ type: '{{ old('type', $lesson->type) }}' }">
            @csrf @method('PUT')

            <x-card class="space-y-5">
                <p class="eyebrow">Lesson details</p>
                <x-form.input label="Title" name="title" :value="$lesson->title" required />
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-form.label for="type" value="Type" />
                        <select name="type" id="type" class="field" x-model="type">
                            @foreach (['video', 'article', 'quiz', 'assignment', 'resource'] as $type)
                                <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                            @endforeach
                        </select>
                        <x-form.error name="type" />
                    </div>
                    <x-form.input label="Duration (minutes)" name="duration_minutes" type="number" min="0" :value="$lesson->duration_minutes" />
                </div>

                <div x-show="type === 'video'" x-cloak class="space-y-5 rounded-xl bg-surface-ice/70 p-4">
                    <p class="font-mono text-[11px] font-medium uppercase tracking-[0.1em] text-on-surface-variant">Video source</p>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-form.select label="Provider" name="provider">
                            @foreach (['youtube' => 'YouTube', 'vimeo' => 'Vimeo', 'self_hosted' => 'Self-hosted'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('provider', $lesson->video?->provider ?? 'youtube') === $value)>{{ $label }}</option>
                            @endforeach
                        </x-form.select>
                        <x-form.input label="Watch URL" name="url" :value="$lesson->video?->getRawOriginal('url')" placeholder="https://…" />
                    </div>
                    <x-form.input label="Embed URL" name="embed_url" :value="$lesson->video?->embed_url" placeholder="https://…/embed/…" />
                </div>

                <div x-show="type === 'article'" x-cloak>
                    <x-form.textarea label="Article content" name="content" :value="$lesson->content" rows="10" hint="HTML is allowed." />
                </div>

                <label class="flex cursor-pointer items-center gap-2.5">
                    <input type="hidden" name="is_preview" value="0">
                    <input type="checkbox" name="is_preview" value="1" class="check" @checked(old('is_preview', $lesson->is_preview))>
                    <span class="text-sm text-on-surface-variant">Free preview lesson</span>
                </label>
            </x-card>

            <div class="mt-6 flex items-center gap-3">
                <x-btn>
                    <x-icon name="check" class="size-4" /> Save lesson
                </x-btn>
                <x-btn variant="ghost" :href="route('admin.courses.show', $lesson->course_id)">Cancel</x-btn>
            </div>
        </form>

        {{-- Resources --}}
        <div>
            <x-card :padding="false">
                <div class="px-6 pb-2 pt-5">
                    <p class="eyebrow">Resources</p>
                    <p class="mt-1 text-xs text-outline">Downloadable files attached to this lesson.</p>
                </div>
                @forelse ($lesson->resources as $resource)
                    <div class="flex items-center gap-3 border-t border-surface-ice px-6 py-3">
                        <x-icon name="document" class="size-4.5 shrink-0 text-outline" />
                        <div class="min-w-0 flex-1">
                            <a href="{{ $resource->file_url }}" target="_blank" class="block truncate text-sm font-medium text-on-surface hover:text-primary">{{ $resource->name }}</a>
                            <p class="font-mono text-[11px] text-outline">{{ strtoupper($resource->file_type ?? 'file') }} · {{ \Illuminate\Support\Number::fileSize($resource->size_bytes ?? 0) }}</p>
                        </div>
                        <x-confirm-form :action="route('admin.lessons.resources.destroy', [$lesson, $resource])" method="DELETE"
                            title="Remove resource" :message="'Remove '.$resource->name.'?'" confirm-label="Remove"
                            class="rounded-lg p-1.5 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                            <x-icon name="trash" class="size-3.5" />
                        </x-confirm-form>
                    </div>
                @empty
                    <p class="border-t border-surface-ice px-6 py-4 text-sm text-outline">No resources yet.</p>
                @endforelse

                <div class="border-t border-surface-ice px-6 py-4">
                    <form method="POST" action="{{ route('admin.lessons.resources.store', $lesson) }}" enctype="multipart/form-data" class="space-y-3">
                        @csrf
                        <input type="file" name="file" required
                            class="block w-full text-sm text-on-surface-variant file:mr-3 file:rounded-lg file:border-0 file:bg-primary/10 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary hover:file:bg-primary/15">
                        <x-form.error name="file" />
                        <x-btn size="sm" variant="secondary">
                            <x-icon name="upload" class="size-4" /> Upload resource
                        </x-btn>
                    </form>
                </div>
            </x-card>
        </div>
    </div>
</x-admin.layout>
