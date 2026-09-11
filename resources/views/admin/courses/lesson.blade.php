<x-admin.layout :title="'Edit lesson — '.$lesson->title">
    <x-page-header
        eyebrow="Course builder"
        :title="$lesson->title"
        :description="'Module “'.$lesson->module?->title.'” · '.$lesson->course?->title"
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Course Content' => route('admin.courses.index'), ($lesson->course?->title ?? 'Course') => route('admin.courses.show', $lesson->course_id), $lesson->title => null]"
    >
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.courses.show', $lesson->course_id)">
                <x-icon name="arrow-left" class="size-4" /> Back to builder
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <div class="grid max-w-5xl gap-6 xl:grid-cols-3">
        <form method="POST" action="{{ route('admin.lessons.update', $lesson) }}" enctype="multipart/form-data" class="xl:col-span-2"
            x-data="{ type: '{{ old('type', $lesson->type) }}' }">
            @csrf @method('PUT')

            <x-form.errors-summary />
            <x-card class="space-y-5">
                <p class="eyebrow">Lesson details</p>
                <x-form.input label="Title" name="title" :value="$lesson->title" required />
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-form.label for="type" value="Type" />
                        <select name="type" id="type" class="field" x-model="type">
                            @foreach (['video', 'article', 'quiz', 'assignment', 'resource'] as $type)
                                <option value="{{ $type }}" @selected(old('type', $lesson->type) === $type)>{{ ucfirst($type) }}</option>
                            @endforeach
                        </select>
                        <x-form.error name="type" />
                    </div>
                    <x-form.input label="Duration (minutes)" name="duration_minutes" type="number" min="0" :value="$lesson->duration_minutes" />
                </div>

                <div x-show="type === 'video'" x-cloak class="space-y-5 rounded-xl bg-surface-ice/70 p-4">
                    {{-- "Premium Video" is the section's name to a student. The provider
                         list underneath is unchanged and still includes YouTube —
                         it is how a lesson plays video at all, not a brand on show. --}}
                    <p class="font-mono text-[11px] font-medium uppercase tracking-[0.1em] text-on-surface-variant">Premium Video source</p>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-form.select label="Provider" name="provider">
                            @foreach (['youtube' => 'YouTube', 'vimeo' => 'Vimeo', 'self_hosted' => 'Self-hosted'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('provider', $lesson->video?->provider ?? 'youtube') === $value)>{{ $label }}</option>
                            @endforeach
                        </x-form.select>
                        <x-form.input label="Watch URL" name="url" :value="$lesson->video?->getRawOriginal('url')" placeholder="https://…" />
                    </div>
                    <x-form.input label="Embed URL" name="embed_url" :value="$lesson->video?->embed_url" placeholder="https://…/embed/…" />
                    <div class="flex flex-wrap items-end gap-4">
                        @if ($lesson->video?->thumbnail_url)
                            <img src="{{ $lesson->video->thumbnail_url }}" alt="Current thumbnail"
                                class="h-16 w-28 shrink-0 rounded-lg border border-outline-variant/40 object-cover">
                        @endif
                        <div class="min-w-0 flex-1">
                            {{-- nullable|image|max:1024 — an image field. --}}
                            <x-form.dropzone
                                name="thumbnail"
                                label="Thumbnail"
                                accept="image/*"
                                accept-label="PNG, JPG, WEBP"
                                :max-kb="1024"
                                preview
                                hint="Shown on the lesson card in the student portal."
                            />
                        </div>
                    </div>
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
                    <p class="mt-1 text-xs text-outline">Files to download and links to follow.</p>
                </div>
                @forelse ($lesson->resources as $resource)
                    <div class="flex items-center gap-3 border-t border-surface-ice px-6 py-3">
                        {{-- The icon follows `kind`, not a guess at which column
                             happens to be filled in. --}}
                        <x-icon :name="$resource->isLink() ? ($resource->is_youtube ? 'play' : 'external') : 'document'"
                            class="size-4.5 shrink-0 text-outline" />
                        <div class="min-w-0 flex-1">
                            <a href="{{ $resource->target_url }}" target="_blank" rel="noopener noreferrer"
                                class="block truncate text-sm font-medium text-on-surface hover:text-primary">{{ $resource->name }}</a>
                            <p class="truncate font-mono text-[11px] text-outline">
                                @if ($resource->isLink())
                                    {{ $resource->is_youtube ? 'YOUTUBE' : 'LINK' }} · {{ $resource->url }}
                                @else
                                    {{ strtoupper($resource->file_type ?? 'file') }} · {{ \Illuminate\Support\Number::fileSize($resource->size_bytes ?? 0) }}
                                @endif
                            </p>
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

                {{-- One form, two kinds. `kind` is posted explicitly rather
                     than inferred from which field was filled in, so the
                     validator can require the right one and say why. --}}
                <div class="border-t border-surface-ice px-6 py-4" x-data="{ kind: 'file' }">
                    <form method="POST" action="{{ route('admin.lessons.resources.store', $lesson) }}" enctype="multipart/form-data" class="space-y-3">
                        @csrf
                        <input type="hidden" name="kind" :value="kind">

                        <div class="flex gap-1 rounded-lg bg-surface-ice p-1">
                            @foreach (['file' => 'Upload a file', 'link' => 'Add a link'] as $value => $label)
                                <button type="button" x-on:click="kind = '{{ $value }}'"
                                    class="flex-1 rounded-md px-3 py-1.5 text-xs font-medium transition"
                                    :class="kind === '{{ $value }}' ? 'bg-white text-primary shadow-card' : 'text-on-surface-variant hover:text-on-surface'">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>

                        <div x-show="kind === 'link'" x-cloak class="space-y-3">
                            {{-- link_url / link_name, because the lesson form
                                 above already posts a `url` for the video and
                                 old() cannot tell two same-named fields apart. --}}
                            <x-form.input label="Title" name="link_name" :value="old('link_name')"
                                placeholder="What is this link?" />
                            <x-form.input label="URL" name="link_url" type="url" :value="old('link_url')"
                                placeholder="https://…"
                                hint="A website or a YouTube link. Must start with http:// or https://." />
                        </div>

                        <div x-show="kind === 'file'" x-cloak>
                            {{-- file|max:5120, no mimes — so an archive of
                                 course material is accepted, as it already was. --}}
                            <x-form.dropzone name="file" :max-kb="5120"
                                hint="Slides, starter code or a .zip of materials." />
                        </div>
                        <x-btn size="sm" variant="secondary">
                            <x-icon name="upload" class="size-4" /> <span x-text="kind === 'link' ? 'Add link' : 'Upload resource'">Upload resource</span>
                        </x-btn>
                    </form>
                </div>
            </x-card>
        </div>
    </div>
</x-admin.layout>
