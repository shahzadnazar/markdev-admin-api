<x-admin.layout title="Quizzes">
    <x-page-header eyebrow="Learning" title="Quizzes" description="Knowledge checks, their questions and attempt history.">
        <x-slot:actions>
            @can('quizzes.create')
                <x-btn :href="route('admin.quizzes.create')">
                    <x-icon name="plus" class="size-4" /> New quiz
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar results="quizzes-results" :action="route('admin.quizzes.index')">
        <div class="w-full sm:w-64">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Quiz title…" class="field">
        </div>
        <x-form.multiselect name="course" label="Course" placeholder="All courses" width="w-64"
            :options="$courses->pluck('title', 'id')->all()" :selected="$selected['course']" />
    </x-filter-bar>

    <div id="quizzes-results" class="transition-opacity duration-150">
        @include('admin.quizzes._results')
    </div>

</x-admin.layout>
