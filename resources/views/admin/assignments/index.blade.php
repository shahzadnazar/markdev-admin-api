<x-admin.layout title="Assignments">
    <x-page-header eyebrow="Learning" title="Assignments" description="Course work, deadlines and grading queues.">
        <x-slot:actions>
            @can('assignments.create')
                <x-btn :href="route('admin.assignments.create')">
                    <x-icon name="plus" class="size-4" /> New assignment
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar results="assignments-results" :action="route('admin.assignments.index')">
        <div class="w-full sm:w-64">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Assignment title…" class="field">
        </div>
        <x-form.multiselect name="course" label="Course" placeholder="All courses" width="w-64"
            :options="$courses->pluck('title', 'id')->all()" :selected="$selected['course']" />
    </x-filter-bar>

    <div id="assignments-results" class="transition-opacity duration-150">
        @include('admin.assignments._results')
    </div>

</x-admin.layout>
