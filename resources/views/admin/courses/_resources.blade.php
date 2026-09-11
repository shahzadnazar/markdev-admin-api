{{--
    Course-level resources: files and links belonging to a whole course rather
    than to any one lesson.

    One partial, rendered in two places. On the course builder the owning course
    is already known, so the form has no picker and posts straight at it; on the
    Course Content list it has to ask which course, because that page is not
    scoped to one. Sharing the markup is the point — the http/https rule, the
    kind discriminator and the 5 MB cap live once in StoresResources, and the
    form that feeds them should live once too.

    @param $resources  course-level rows the viewer may see, `course` loaded
    @param $course     the owning course, or null on the list page
    @param $courses    courses the viewer may add to; only read when $course is null
    @param $showCourse whether each row names its course (pointless on one course's page)
--}}
@props([
    'resources',
    'course' => null,
    'courses' => null,
    'showCourse' => false,
])

@php
    /*
     * The action is a template because the course comes from a <select>. Built
     * from the router rather than written out, so the URL shape stays owned by
     * routes/web.php — a renamed route breaks here loudly instead of 404ing at
     * submit time.
     */
    $actionTemplate = $course
        ? null
        : route('admin.courses.resources.store', ['course' => '__COURSE__']);
@endphp

<x-card :padding="false" x-data="{ kind: 'file', courseId: '{{ $courses?->first()?->id }}' }">
    <div class="px-6 pt-5 pb-2">
        <p class="eyebrow">Course resources</p>
        <p class="mt-1 text-xs text-outline">
            @if ($course)
                Files and links for the whole course. Enrolled students see these on their Notes page.
            @else
                Files and links attached to a course rather than to one of its lessons.
                Enrolled students see these on their Notes page.
            @endif
        </p>
    </div>

    @forelse ($resources as $resource)
        <div class="flex items-center gap-3 border-t border-surface-ice px-6 py-3">
            <x-icon :name="$resource->isLink() ? ($resource->is_youtube ? 'play' : 'external') : 'document'"
                class="size-4.5 shrink-0 text-outline" />
            <div class="min-w-0 flex-1">
                <a href="{{ $resource->target_url }}" target="_blank" rel="noopener noreferrer"
                    class="block truncate text-sm font-medium text-on-surface hover:text-primary">{{ $resource->name }}</a>
                <p class="truncate font-mono text-[11px] text-outline">
                    @if ($showCourse && $resource->course)
                        {{-- Which course this belongs to: on a list covering
                             every course, the name alone does not say. --}}
                        <span class="text-on-surface-variant">{{ $resource->course->title }}</span> ·
                    @endif
                    @if ($resource->isLink())
                        {{ $resource->is_youtube ? 'YOUTUBE' : 'LINK' }} · {{ $resource->url }}
                    @else
                        {{ strtoupper($resource->file_type ?? 'file') }} · {{ \Illuminate\Support\Number::fileSize($resource->size_bytes ?? 0) }}
                    @endif
                </p>
            </div>
            @can('courses.update')
                <x-confirm-form :action="route('admin.courses.resources.destroy', [$resource->course_id, $resource])" method="DELETE"
                    title="Remove resource" :message="'Remove '.$resource->name.'?'" confirm-label="Remove"
                    class="rounded-lg p-1.5 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                    <x-icon name="trash" class="size-3.5" />
                </x-confirm-form>
            @endcan
        </div>
    @empty
        <p class="border-t border-surface-ice px-6 py-4 text-sm text-outline">No course resources yet.</p>
    @endforelse

    @if ($resources instanceof \Illuminate\Contracts\Pagination\Paginator && $resources->hasPages())
        <div class="border-t border-surface-ice px-6 py-3">{{ $resources->links() }}</div>
    @endif

    @can('courses.update')
        @if ($course || $courses?->isNotEmpty())
            <div class="border-t border-surface-ice px-6 py-4">
                <form method="POST" enctype="multipart/form-data" class="space-y-3"
                    @if ($course)
                        action="{{ route('admin.courses.resources.store', $course) }}"
                    @else
                        {{-- Alpine, because the target course is chosen in the form.
                             The kind switcher below already requires JS, so this adds
                             no new dependency; and whatever course id arrives, the
                             server still runs authorizeCourseAccess on it. --}}
                        :action="'{{ $actionTemplate }}'.replace('__COURSE__', courseId)"
                    @endif
                >
                    @csrf
                    {{-- kind is posted explicitly rather than inferred from
                         which field was filled in, so the validator can
                         require the right one and say why. --}}
                    <input type="hidden" name="kind" :value="kind">

                    @unless ($course)
                        <x-form.select label="Course" name="course_picker" required x-model="courseId">
                            @foreach ($courses as $selectable)
                                <option value="{{ $selectable->id }}">{{ $selectable->title }}</option>
                            @endforeach
                        </x-form.select>
                    @endunless

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
                        {{-- These names are the validator's, not this form's:
                             StoresResources requires link_name and link_url by
                             those exact names, so neither may be prefixed. --}}
                        <x-form.input label="Title" name="link_name" :value="old('link_name')" placeholder="What is this link?" />
                        <x-form.input label="URL" name="link_url" type="url" :value="old('link_url')" placeholder="https://…"
                            hint="A website or a YouTube link. Must start with http:// or https://." />
                    </div>

                    <div x-show="kind === 'file'" x-cloak>
                        {{-- Its own id: a drop zone is a <label for>, and the
                             add-lesson modals on the builder each render one. --}}
                        <x-form.dropzone name="file" id="course-resource-file" :max-kb="5120"
                            hint="Slides, a syllabus or a .zip of materials." />
                    </div>

                    <x-btn size="sm" variant="secondary">
                        <x-icon name="upload" class="size-4" />
                        <span x-text="kind === 'link' ? 'Add link' : 'Upload resource'">Upload resource</span>
                    </x-btn>
                </form>
            </div>
        @endif
    @endcan
</x-card>
