<x-admin.layout title="Courses">
    <x-page-header eyebrow="Learning" title="Courses"
        :description="auth()->user()->hasAnyRole(['super-admin', 'admin', 'manager'])
            ? 'The full catalog — draft, published and archived courses.'
            : 'Your courses — draft, published and archived.'">
        <x-slot:actions>
            @can('courses.create')
                <x-btn :href="route('admin.courses.create')">
                    <x-icon name="plus" class="size-4" /> New course
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar :action="route('admin.courses.index')">
        <div class="w-full sm:w-60">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Course title…" class="field">
        </div>
        <div class="w-44">
            <x-form.label for="category" value="Category" />
            <select name="category" id="category" class="field">
                <option value="">All</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(request('category') == $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="w-40">
            <x-form.label for="level" value="Level" />
            <select name="level" id="level" class="field">
                <option value="">Any</option>
                @foreach (['beginner', 'intermediate', 'advanced'] as $level)
                    <option value="{{ $level }}" @selected(request('level') === $level)>{{ ucfirst($level) }}</option>
                @endforeach
            </select>
        </div>
        <div class="w-40">
            <x-form.label for="status" value="Status" />
            <select name="status" id="status" class="field">
                <option value="">Any</option>
                @foreach (['draft', 'published', 'archived'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <label class="flex h-[42px] cursor-pointer items-center gap-2 rounded-lg border border-outline-variant bg-white px-3">
            <input type="checkbox" name="trashed" value="1" @checked(request('trashed') === '1') class="check">
            <span class="text-sm text-on-surface-variant">Trashed</span>
        </label>
    </x-filter-bar>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Course</th>
                <th class="th">Instructor</th>
                <th class="th">Level</th>
                <th class="th">Price</th>
                <th class="th">Lessons</th>
                <th class="th">Enrolled</th>
                <th class="th">Status</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($courses as $course)
                <tr class="row">
                    <td class="td">
                        <div class="flex items-center gap-3">
                            @if ($course->thumbnail_path)
                                <img src="{{ $course->thumbnail_url }}" alt="" class="size-10 shrink-0 rounded-lg object-cover">
                            @else
                                <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-primary/15 to-secondary/15 text-primary">
                                    <x-icon name="academic-cap" class="size-5" />
                                </div>
                            @endif
                            <div class="min-w-0">
                                @if ($course->trashed())
                                    <p class="max-w-[16rem] truncate font-medium text-on-surface">{{ $course->title }}</p>
                                @else
                                    <a href="{{ route('admin.courses.show', $course) }}" class="block max-w-[16rem] truncate font-medium text-on-surface hover:text-primary">{{ $course->title }}</a>
                                @endif
                                <p class="text-xs text-outline">{{ $course->category?->name ?? 'Uncategorised' }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="td text-on-surface-variant">{{ $course->instructor?->name ?? '—' }}</td>
                    <td class="td"><x-badge variant="secondary">{{ $course->level }}</x-badge></td>
                    <td class="td font-mono text-xs">{{ $course->is_free ? 'Free' : '$'.number_format((float) $course->price, 2) }}</td>
                    <td class="td font-mono text-xs">{{ $course->lessons_count }}</td>
                    <td class="td font-mono text-xs">{{ $course->enrollments_count }}</td>
                    <td class="td">
                        @if ($course->trashed())
                            <x-badge variant="danger">Trashed</x-badge>
                        @else
                            <x-badge :variant="match($course->status) { 'published' => 'success', 'draft' => 'warning', default => 'neutral' }">{{ $course->status }}</x-badge>
                        @endif
                    </td>
                    <td class="td">
                        <div class="flex items-center justify-end gap-1">
                            @if ($course->trashed())
                                @can('courses.restore')
                                    <x-confirm-form :action="route('admin.courses.restore', $course)" method="POST" variant="primary"
                                        title="Restore course" :message="'Restore '.$course->title.'?'" confirm-label="Restore"
                                        class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                        <x-icon name="restore" class="size-4" />
                                    </x-confirm-form>
                                @endcan
                                @can('courses.delete')
                                    <x-confirm-form :action="route('admin.courses.force-destroy', $course)" method="DELETE"
                                        title="Delete forever" :message="'Permanently delete '.$course->title.'? All curriculum data is lost.'" confirm-label="Delete forever"
                                        class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                        <x-icon name="trash" class="size-4" />
                                    </x-confirm-form>
                                @endcan
                            @else
                                <a href="{{ route('admin.courses.show', $course) }}" title="Course builder" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                    <x-icon name="eye" class="size-4" />
                                </a>
                                @can('courses.update')
                                    <a href="{{ route('admin.courses.edit', $course) }}" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                        <x-icon name="pencil" class="size-4" />
                                    </a>
                                @endcan
                                @can('courses.delete')
                                    <x-confirm-form :action="route('admin.courses.destroy', $course)" method="DELETE"
                                        title="Move to trash" :message="'Move '.$course->title.' to trash? Students lose access until restored.'" confirm-label="Move to trash"
                                        class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                        <x-icon name="trash" class="size-4" />
                                    </x-confirm-form>
                                @endcan
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-empty-state icon="academic-cap" title="No courses found" description="Try different filters, or create a new course." />
                    </td>
                </tr>
            @endforelse
        </tbody>
        <x-slot:footer>
            {{ $courses->links() }}
        </x-slot:footer>
    </x-table>
</x-admin.layout>
