<x-admin.layout title="Notes">

    <x-page-header
        eyebrow="Learning"
        title="Notes"
        description="Manage course notes and learning materials."
    >
        <x-slot:actions>
            @can('notes.create')
                <x-btn :href="route('admin.notes.create')">
                    <x-icon name="plus" class="size-4" />
                    Upload note
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar results="notes-results" :action="route('admin.notes.index')">

        <div class="w-full sm:w-60">
            <x-form.label for="search" value="Search" />
            <input
                type="search"
                name="search"
                id="search"
                value="{{ request('search') }}"
                placeholder="Note title…"
                class="field"
            >
        </div>

        <div class="w-full sm:w-60">
            <x-form.label for="course" value="Course" />

            <select name="course" id="course" class="field">
                <option value="">All courses</option>

                @foreach ($courses as $course)
                    <option
                        value="{{ $course->id }}"
                        @selected(request('course') == $course->id)
                    >
                        {{ $course->title }}
                    </option>
                @endforeach
            </select>
        </div>

    </x-filter-bar>

    <div id="notes-results" class="transition-opacity duration-150">
        @include('admin.notes._results')
    </div>

</x-admin.layout>