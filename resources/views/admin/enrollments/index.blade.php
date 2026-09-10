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
        <x-form.multiselect name="course" label="Course" placeholder="All courses" width="w-64"
            :options="$courses->pluck('title', 'id')->all()" :selected="$selected['course']" />
    </x-filter-bar>

    <div id="enrollments-results" class="transition-opacity duration-150">
        @include('admin.enrollments._results')
    </div>

</x-admin.layout>
