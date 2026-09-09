<x-admin.layout title="Certificates">
    <x-page-header eyebrow="Learning" title="Certificates" description="Issued certificates across all courses.">
        <x-slot:actions>
            @can('certificates.issue')
                <x-btn :href="route('admin.certificates.create')">
                    <x-icon name="plus" class="size-4" /> Issue certificate
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <x-filter-bar results="certificates-results" :action="route('admin.certificates.index')">
        <div class="w-full sm:w-72">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Student, course or number…" class="field">
        </div>
    </x-filter-bar>

    <div id="certificates-results" class="transition-opacity duration-150">
        @include('admin.certificates._results')
    </div>

</x-admin.layout>
