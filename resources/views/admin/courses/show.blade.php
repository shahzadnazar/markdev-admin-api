<x-admin.layout :title="$course->title">
    <x-page-header eyebrow="Course builder" :title="$course->title"
        :crumbs="['Dashboard' => route('admin.dashboard'), 'Courses' => route('admin.courses.index'), $course->title => null]">
        <x-slot:actions>
            @can('courses.update')
                <x-confirm-form :action="route('admin.courses.publish', $course)" method="POST"
                    :variant="$course->status === 'published' ? 'danger' : 'primary'"
                    :title="$course->status === 'published' ? 'Unpublish course' : 'Publish course'"
                    :message="$course->status === 'published' ? 'Students will lose catalog access until you publish again.' : 'The course becomes visible to students immediately.'"
                    :confirm-label="$course->status === 'published' ? 'Unpublish' : 'Publish'"
                    class="{{ $course->status === 'published'
                        ? 'inline-flex items-center gap-2 rounded-lg border border-outline-variant bg-white px-4 py-2.5 text-sm font-medium text-on-surface-variant transition hover:border-warning hover:text-warning'
                        : 'inline-flex items-center gap-2 rounded-lg bg-success px-4 py-2.5 text-sm font-medium text-white shadow-card transition hover:opacity-90' }}">
                    <x-icon :name="$course->status === 'published' ? 'archive' : 'play'" class="size-4" />
                    {{ $course->status === 'published' ? 'Unpublish' : 'Publish' }}
                </x-confirm-form>
                <x-btn variant="secondary" :href="route('admin.courses.edit', $course)">
                    <x-icon name="pencil" class="size-4" /> Edit details
                </x-btn>
            @endcan
            <x-btn variant="ghost" :href="route('admin.courses.index')">
                <x-icon name="arrow-left" class="size-4" /> Back
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    {{-- Meta strip --}}
    <div class="mb-8 flex flex-wrap items-center gap-2">
        <x-badge :variant="match($course->status) { 'published' => 'success', 'draft' => 'warning', default => 'neutral' }">{{ $course->status }}</x-badge>
        <x-badge variant="secondary">{{ $course->level }}</x-badge>
        <x-badge variant="primary">{{ $course->category?->name ?? 'uncategorised' }}</x-badge>
        <x-badge variant="neutral">{{ $course->is_free ? 'free' : '$'.number_format((float) $course->price, 2) }}</x-badge>
        <x-badge variant="neutral">{{ $course->enrollments_count }} enrolled</x-badge>
        <x-badge variant="neutral">{{ $course->instructor?->name ?? 'no instructor' }}</x-badge>
    </div>

    <div class="max-w-4xl space-y-5">
        @forelse ($course->modules as $module)
            <x-card :padding="false" x-data="{ renaming: false }">
                {{-- Module header --}}
                <div class="flex items-center gap-3 px-6 py-4">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 font-mono text-xs font-semibold text-primary">{{ sprintf('%02d', $loop->iteration) }}</span>

                    <div class="min-w-0 flex-1">
                        <p x-show="! renaming" class="truncate font-display text-base font-semibold text-on-surface">{{ $module->title }}</p>
                        @can('courses.update')
                            <form x-show="renaming" x-cloak method="POST" action="{{ route('admin.modules.update', $module) }}" class="flex items-center gap-2">
                                @csrf @method('PUT')
                                <input type="text" name="title" value="{{ $module->title }}" required class="field py-1.5">
                                <x-btn size="sm">Save</x-btn>
                                <x-btn type="button" size="sm" variant="ghost" x-on:click="renaming = false">Cancel</x-btn>
                            </form>
                        @endcan
                    </div>

                    @can('courses.update')
                        <div class="flex shrink-0 items-center gap-1" x-show="! renaming">
                            <form method="POST" action="{{ route('admin.modules.move', $module) }}">
                                @csrf <input type="hidden" name="direction" value="up">
                                <button type="submit" @disabled($loop->first) class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary disabled:opacity-30" title="Move up">
                                    <x-icon name="arrow-up" class="size-4" />
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.modules.move', $module) }}">
                                @csrf <input type="hidden" name="direction" value="down">
                                <button type="submit" @disabled($loop->last) class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary disabled:opacity-30" title="Move down">
                                    <x-icon name="arrow-down" class="size-4" />
                                </button>
                            </form>
                            <button type="button" x-on:click="renaming = true" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary" title="Rename">
                                <x-icon name="pencil" class="size-4" />
                            </button>
                            <x-confirm-form :action="route('admin.modules.destroy', $module)" method="DELETE"
                                title="Delete module" :message="'Delete '.$module->title.' and all of its lessons?'" confirm-label="Delete module"
                                class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                <x-icon name="trash" class="size-4" />
                            </x-confirm-form>
                        </div>
                    @endcan
                </div>

                {{-- Lessons --}}
                <div class="border-t border-surface-ice">
                    @forelse ($module->lessons as $lesson)
                        <div class="flex items-center gap-3 border-b border-surface-ice px-6 py-3 last:border-b-0">
                            <span class="w-6 shrink-0 text-center font-mono text-[11px] text-outline">{{ $loop->iteration }}</span>
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg {{ match($lesson->type) {
                                'video' => 'bg-primary/10 text-primary',
                                'article' => 'bg-secondary/10 text-secondary',
                                'quiz' => 'bg-warning-container text-warning',
                                'assignment' => 'bg-success-container text-success',
                                default => 'bg-on-surface-variant/10 text-on-surface-variant',
                            } }}">
                                <x-icon :name="match($lesson->type) { 'video' => 'play', 'article' => 'document', 'quiz' => 'quiz', 'assignment' => 'clipboard', default => 'document' }" class="size-4" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-on-surface">{{ $lesson->title }}</p>
                                <p class="flex flex-wrap items-center gap-x-2 text-[11px] text-outline">
                                    <span class="font-mono uppercase tracking-[0.06em]">{{ $lesson->type }}</span>
                                    @if ($lesson->duration_minutes)
                                        <span>· {{ $lesson->duration_minutes }} min</span>
                                    @endif
                                    @if ($lesson->resources->isNotEmpty())
                                        <span>· {{ $lesson->resources->count() }} resource{{ $lesson->resources->count() === 1 ? '' : 's' }}</span>
                                    @endif
                                </p>
                            </div>
                            @if ($lesson->is_preview)
                                <x-badge variant="success">preview</x-badge>
                            @endif
                            @if ($lesson->type === 'video' && $lesson->video)
                                @php $stats = ($watchStats ?? collect())->get($lesson->id); @endphp
                                <button type="button" x-data
                                    x-on:click="$dispatch('lesson-watch', {
                                        title: @js($lesson->title),
                                        rows: @js(($stats['rows'] ?? collect())->map(fn ($row) => [
                                            'name' => $row->user?->name ?? 'Unknown',
                                            'email' => $row->user?->email,
                                            'coverage' => $row->coverage_percent,
                                            'verdict' => $row->verdict(),
                                            'segments' => $row->segments ?? [],
                                            'skipped' => $row->skippedSegments(),
                                            'duration' => $row->duration_seconds,
                                            'seen' => $row->last_seen_at?->diffForHumans(),
                                        ])->values()),
                                    }); $dispatch('open-modal', 'lesson-watch')"
                                    class="shrink-0 rounded-lg px-2 py-1 font-mono text-[10px] uppercase tracking-[0.08em] text-on-surface-variant transition hover:bg-primary/10 hover:text-primary"
                                    title="Who watched this lecture">
                                    {{ $stats['full'] ?? 0 }}/{{ $stats['enrolled'] ?? 0 }} watched
                                </button>
                            @endif
                            @if ($lesson->type === 'video' && $lesson->video && ($lesson->video->embed_src || $lesson->video->provider === 'self_hosted'))
                                <button type="button" x-data
                                    x-on:click="$dispatch('lesson-preview', {
                                        title: @js($lesson->title),
                                        src: @js($lesson->video->embed_src),
                                        file: @js($lesson->video->provider === 'self_hosted' ? $lesson->video->url : null),
                                    }); $dispatch('open-modal', 'lesson-preview')"
                                    class="shrink-0 rounded-lg p-1.5 text-primary transition hover:bg-primary/10"
                                    title="Preview lecture" aria-label="Preview lecture “{{ $lesson->title }}”">
                                    <x-icon name="play" class="size-4" />
                                </button>
                            @endif
                            @can('lessons.update')
                                <div class="flex shrink-0 items-center gap-1">
                                    <form method="POST" action="{{ route('admin.lessons.move', $lesson) }}">
                                        @csrf <input type="hidden" name="direction" value="up">
                                        <button type="submit" @disabled($loop->first) class="rounded-lg p-1.5 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary disabled:opacity-30" title="Move up">
                                            <x-icon name="arrow-up" class="size-3.5" />
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.lessons.move', $lesson) }}">
                                        @csrf <input type="hidden" name="direction" value="down">
                                        <button type="submit" @disabled($loop->last) class="rounded-lg p-1.5 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary disabled:opacity-30" title="Move down">
                                            <x-icon name="arrow-down" class="size-3.5" />
                                        </button>
                                    </form>
                                    <a href="{{ route('admin.lessons.edit', $lesson) }}" class="rounded-lg p-1.5 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary" title="Edit lesson">
                                        <x-icon name="pencil" class="size-3.5" />
                                    </a>
                                    @can('lessons.delete')
                                        <x-confirm-form :action="route('admin.lessons.destroy', $lesson)" method="DELETE"
                                            title="Delete lesson" :message="'Delete '.$lesson->title.'?'" confirm-label="Delete lesson"
                                            class="rounded-lg p-1.5 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                            <x-icon name="trash" class="size-3.5" />
                                        </x-confirm-form>
                                    @endcan
                                </div>
                            @endcan
                        </div>
                    @empty
                        <p class="px-6 py-4 text-sm text-outline">No lessons in this module yet.</p>
                    @endforelse

                    @can('lessons.create')
                        <div class="px-6 py-3">
                            <button type="button" x-data x-on:click="$dispatch('open-modal', 'add-lesson-{{ $module->id }}')"
                                class="inline-flex items-center gap-2 text-sm font-medium text-primary transition hover:text-primary-deep">
                                <x-icon name="plus" class="size-4" /> Add lesson
                            </button>
                        </div>
                    @endcan
                </div>
            </x-card>

            {{-- Add-lesson modal for this module --}}
            @can('lessons.create')
                @php($reopen = $errors->any() && old('module_id') == $module->id)
                <x-modal :name="'add-lesson-'.$module->id" max-width="xl">
                    <form method="POST" action="{{ route('admin.lessons.store', $module) }}" enctype="multipart/form-data" class="p-6"
                        x-data="{ type: '{{ $reopen ? old('type', 'video') : 'video' }}' }"
                        @if ($reopen) x-init="$nextTick(() => $dispatch('open-modal', 'add-lesson-{{ $module->id }}'))" @endif>
                        @csrf
                        <input type="hidden" name="module_id" value="{{ $module->id }}">
                        <h3 class="font-display text-lg font-semibold text-on-surface">Add lesson to “{{ $module->title }}”</h3>
                        <div class="mt-5 space-y-4">
                            @if ($reopen)
                                <x-form.errors-summary />
                            @endif
                            <x-form.input label="Title" name="title" required :value="$reopen ? old('title') : null" />
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <x-form.label for="type-{{ $module->id }}" value="Type" />
                                    <select name="type" id="type-{{ $module->id }}" class="field" x-model="type">
                                        @foreach (['video', 'article', 'quiz', 'assignment', 'resource'] as $type)
                                            <option value="{{ $type }}" @selected($reopen && old('type') === $type)>{{ ucfirst($type) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <x-form.input label="Duration (minutes)" name="duration_minutes" type="number" min="0" :value="$reopen ? old('duration_minutes', 10) : 10" />
                            </div>

                            <div x-show="type === 'video'" x-cloak class="space-y-4 rounded-xl bg-surface-ice/70 p-4">
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <x-form.label value="Provider" />
                                        <select name="provider" class="field">
                                            <option value="youtube" @selected($reopen && old('provider') === 'youtube')>YouTube</option>
                                            <option value="vimeo" @selected($reopen && old('provider') === 'vimeo')>Vimeo</option>
                                            <option value="self_hosted" @selected($reopen && old('provider') === 'self_hosted')>Self-hosted</option>
                                        </select>
                                    </div>
                                    <x-form.input label="Watch URL" name="url" placeholder="https://…" :value="$reopen ? old('url') : null" />
                                </div>
                                <x-form.input label="Embed URL" name="embed_url" placeholder="https://…/embed/…" :value="$reopen ? old('embed_url') : null" />
                                <x-form.input type="file" label="Thumbnail (optional)" name="thumbnail" accept="image/*"
                                    hint="JPG/PNG up to 2 MB — shown on the lesson card in the student portal." />
                            </div>

                            <div x-show="type === 'article'" x-cloak>
                                <x-form.textarea label="Article content" name="content" rows="6" hint="HTML is allowed." />
                            </div>

                            <label class="flex cursor-pointer items-center gap-2.5">
                                <input type="hidden" name="is_preview" value="0">
                                <input type="checkbox" name="is_preview" value="1" class="check" @checked($reopen && old('is_preview'))>
                                <span class="text-sm text-on-surface-variant">Free preview lesson</span>
                            </label>
                        </div>
                        <div class="mt-6 flex justify-end gap-3">
                            <x-btn type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'add-lesson-{{ $module->id }}')">Cancel</x-btn>
                            <x-btn>Add lesson</x-btn>
                        </div>
                    </form>
                </x-modal>
            @endcan
        @empty
            <x-card>
                <x-empty-state icon="academic-cap" title="No modules yet" description="Start the curriculum by adding the first module below." class="py-10" />
            </x-card>
        @endforelse

        {{-- Add module --}}
        @can('courses.update')
            <x-card>
                <form method="POST" action="{{ route('admin.modules.store', $course) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div class="min-w-64 flex-1">
                        <x-form.label for="new-module-title" value="New module" />
                        <input type="text" name="title" id="new-module-title" placeholder="e.g. Getting productive" required class="field">
                        <x-form.error name="title" />
                    </div>
                    <x-btn>
                        <x-icon name="plus" class="size-4" /> Add module
                    </x-btn>
                </form>
            </x-card>
        @endcan
    </div>

    {{-- Shared lecture-preview modal: fed by the per-lesson play buttons.
         The src is cleared whenever the modal closes so playback stops. --}}
    {{-- Who watched a lecture, and which parts they skipped. --}}
    <x-modal name="lesson-watch" max-width="3xl">
        <div class="p-6" x-data="{ title: '', rows: [] }"
            x-on:lesson-watch.window="title = $event.detail.title; rows = $event.detail.rows"
            x-on:close-modal.window="if ($event.detail === 'lesson-watch') { rows = [] }">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="font-mono text-[10px] uppercase tracking-[0.14em] text-outline">Video watch record</p>
                    <h3 class="truncate font-display text-lg font-semibold text-on-surface" x-text="title"></h3>
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', 'lesson-watch')"
                    class="rounded-lg p-1.5 text-on-surface-variant transition hover:bg-error/10 hover:text-error" aria-label="Close">
                    <x-icon name="x" class="size-4" />
                </button>
            </div>

            <p class="mt-1 text-xs text-on-surface-variant">
                Filled bars are the parts actually played. Gaps were skipped —
                {{ $requiredPercent ?? 90 }}% coverage is required to complete the lesson.
            </p>

            <template x-if="rows.length === 0">
                <p class="mt-6 rounded-xl bg-surface-ice/70 p-6 text-center text-sm text-on-surface-variant">
                    No student has played this video yet.
                </p>
            </template>

            <ul class="scroll-thin mt-5 max-h-[26rem] space-y-3 overflow-y-auto pr-1">
                <template x-for="row in rows" :key="row.email || row.name">
                    <li class="rounded-xl border border-outline-variant/60 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-on-surface" x-text="row.name"></p>
                                <p class="truncate font-mono text-[10px] text-outline" x-text="row.email"></p>
                            </div>
                            <div class="shrink-0 text-right">
                                <span class="font-mono text-sm"
                                    :class="{
                                        'text-success': row.verdict === 'watched in full',
                                        'text-warning': row.verdict === 'skipped ahead',
                                        'text-primary': row.verdict === 'left part-way',
                                        'text-outline': row.verdict === 'not started',
                                    }"
                                    x-text="row.coverage + '%'"></span>
                                <p class="font-mono text-[10px] uppercase tracking-[0.08em] text-outline" x-text="row.verdict"></p>
                            </div>
                        </div>

                        <div class="relative mt-2 h-2 overflow-hidden rounded-full bg-surface-ice">
                            <template x-for="(seg, i) in row.segments" :key="i">
                                <span class="absolute inset-y-0"
                                    :class="{
                                        'bg-success': row.verdict === 'watched in full',
                                        'bg-warning': row.verdict === 'skipped ahead',
                                        'bg-primary': row.verdict === 'left part-way',
                                    }"
                                    :style="`left:${(seg[0] / row.duration) * 100}%; width:${Math.max(0.5, ((seg[1]-seg[0]) / row.duration) * 100)}%`"></span>
                            </template>
                        </div>

                        <p class="mt-1.5 font-mono text-[10px] text-outline">
                            <template x-if="row.skipped.length">
                                <span>skipped
                                    <template x-for="(gap, i) in row.skipped" :key="i">
                                        <span x-text="`${Math.floor(gap[0]/60)}:${String(Math.floor(gap[0]%60)).padStart(2,'0')}\u2013${Math.floor(gap[1]/60)}:${String(Math.floor(gap[1]%60)).padStart(2,'0')} `"></span>
                                    </template>
                                </span>
                            </template>
                            <template x-if="!row.skipped.length"><span>no gaps</span></template>
                            <span x-show="row.seen" x-text="` \u00b7 ${row.seen}`"></span>
                        </p>
                    </li>
                </template>
            </ul>
        </div>
    </x-modal>

    <x-modal name="lesson-preview" max-width="3xl">
        <div x-data="{ title: '', src: null, file: null }"
            x-on:lesson-preview.window="title = $event.detail.title; src = $event.detail.src; file = $event.detail.file"
            x-on:close-modal.window="if ($event.detail === 'lesson-preview') { src = null; file = null }"
            x-on:keydown.escape.window="src = null; file = null"
            class="p-4 sm:p-5">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h3 class="min-w-0 truncate font-display text-base font-semibold text-on-surface" x-text="title"></h3>
                <button type="button" x-on:click="src = null; file = null; $dispatch('close-modal', 'lesson-preview')"
                    class="shrink-0 rounded-lg p-1.5 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary"
                    aria-label="Close preview">
                    <x-icon name="x-mark" class="size-4" />
                </button>
            </div>
            <div class="overflow-hidden rounded-xl bg-primary-deep/95">
                <template x-if="src">
                    <iframe :src="src" class="aspect-video w-full" frameborder="0"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen title="Lecture preview"></iframe>
                </template>
                <template x-if="!src && file">
                    <video :src="file" controls class="aspect-video w-full" title="Lecture preview"></video>
                </template>
                <template x-if="!src && !file">
                    <div class="flex aspect-video w-full items-center justify-center text-sm text-white/70">
                        This lesson has no playable video URL yet — add a watch or embed URL first.
                    </div>
                </template>
            </div>
        </div>
    </x-modal>
</x-admin.layout>
