<x-admin.layout title="Enrollments">
    <x-page-header eyebrow="Learning" title="Enrollments" description="Who is enrolled where, and how far along they are.">
        <x-slot:actions>
            @can('enrollments.create')
                <x-btn :href="route('admin.enrollments.create')">
                    <x-icon name="plus" class="size-4" /> Enroll a student
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar results="enrollments-results" :action="route('admin.enrollments.index')">
        <div class="w-full sm:w-64">
            <x-form.label for="search" value="Student" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Name or email…" class="field">
        </div>
        <div class="w-64">
            <x-form.label for="course" value="Course" />
            <select name="course" id="course" class="field">
                <option value="">All courses</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected(request('course') == $course->id)>{{ $course->title }}</option>
                @endforeach
            </select>
        </div>
    </x-filter-bar>

    <div id="enrollments-results" class="transition-opacity duration-150">
        @include('admin.enrollments._results')
    </div>

</x-admin.layout>
