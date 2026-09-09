<x-admin.layout title="Categories">
    <x-page-header eyebrow="Learning" title="Categories" description="Organise the course catalog into browsable topics.">
        <x-slot:actions>
            @can('categories.create')
                <x-btn :href="route('admin.categories.create')">
                    <x-icon name="plus" class="size-4" /> New category
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar results="categories-results" :action="route('admin.categories.index')">
        <div class="w-full sm:w-72">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Category name…" class="field">
        </div>
    </x-filter-bar>

    <div id="categories-results" class="transition-opacity duration-150">
        @include('admin.categories._results')
    </div>

</x-admin.layout>
