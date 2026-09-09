<x-admin.layout title="Announcements">
    <x-page-header eyebrow="Engagement" title="Announcements" description="Broadcasts to the whole school or a single course.">
        <x-slot:actions>
            @can('announcements.create')
                <x-btn :href="route('admin.announcements.create')">
                    <x-icon name="plus" class="size-4" /> New announcement
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar results="announcements-results" :action="route('admin.announcements.index')">
        <div class="w-full sm:w-64">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Title…" class="field">
        </div>
        <div class="w-64">
            <x-form.label for="course" value="Course" />
            <select name="course" id="course" class="field">
                <option value="">All audiences</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected(request('course') == $course->id)>{{ $course->title }}</option>
                @endforeach
            </select>
        </div>
    </x-filter-bar>

    <div id="announcements-results" class="transition-opacity duration-150">
        @include('admin.announcements._results')
    </div>

</x-admin.layout>
