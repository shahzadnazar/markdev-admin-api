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

    <x-filter-bar results="courses-results" :action="route('admin.courses.index')">
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

    <div id="courses-results" class="transition-opacity duration-150">
        @include('admin.courses._results')
    </div>

</x-admin.layout>
