{{-- Label only. Routes, tables and models stay `courses`: renaming those
     would touch every route name, policy string and permission in the
     matrix for a word on a screen. --}}
<x-admin.layout title="Course Content">
    <x-page-header eyebrow="Learning" title="Course Content"
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

    <x-filter-bar results="courses-results" :action="route('admin.courses.index')">
        <div class="w-full sm:w-60">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Course title…" class="field">
        </div>
        <x-form.multiselect name="category" label="Category" placeholder="All"
            :options="$categories->pluck('name', 'id')->all()" :selected="$selected['category']" />

        <x-form.multiselect name="level" label="Level" placeholder="Any" width="w-40"
            :options="collect(\App\Http\Controllers\Admin\CourseController::LEVELS)->mapWithKeys(fn ($l) => [$l => ucfirst($l)])->all()"
            :selected="$selected['level']" />

        <x-form.multiselect name="status" label="Status" placeholder="Any" width="w-40"
            :options="collect(\App\Http\Controllers\Admin\CourseController::STATUSES)->mapWithKeys(fn ($s) => [$s => ucfirst($s)])->all()"
            :selected="$selected['status']" />

        {{-- Only for someone who could restore or empty it. The controller
             decides, so the checkbox and the query string cannot disagree. --}}
        @if ($mayViewTrash)
            <label class="flex h-[42px] cursor-pointer items-center gap-2 rounded-lg border border-outline-variant bg-white px-3">
                <input type="checkbox" name="trashed" value="1" @checked($trashed) class="check">
                <span class="text-sm text-on-surface-variant">Trashed</span>
            </label>
        @endif
    </x-filter-bar>

    <div id="courses-results" class="transition-opacity duration-150">
        @include('admin.courses._results')
    </div>

</x-admin.layout>
