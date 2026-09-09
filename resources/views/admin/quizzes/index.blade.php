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

    <div id="quizzes-results" class="transition-opacity duration-150">
        @include('admin.quizzes._results')
    </div>

</x-admin.layout>
