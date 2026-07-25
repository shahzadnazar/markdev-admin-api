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

    <x-filter-bar :action="route('admin.categories.index')">
        <div class="w-full sm:w-72">
            <x-form.label for="search" value="Search" />
            <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Category name…" class="field">
        </div>
    </x-filter-bar>

    <x-table>
        <thead class="bg-surface-ice/60">
            <tr>
                <th class="th">Category</th>
                <th class="th">Slug</th>
                <th class="th td-num">Courses</th>
                <th class="th text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($categories as $category)
                <tr class="row">
                    <td class="td">
                        <p class="font-medium text-on-surface">{{ $category->name }}</p>
                        @if ($category->description)
                            <p class="mt-0.5 max-w-md truncate text-xs text-outline">{{ $category->description }}</p>
                        @endif
                    </td>
                    <td class="td"><span class="font-mono text-xs text-on-surface-variant">{{ $category->slug }}</span></td>
                    <td class="td td-num"><x-badge variant="primary">{{ $category->courses_count }}</x-badge></td>
                    <td class="td text-right">
                        <div class="flex items-center justify-end gap-1">
                            @can('categories.update')
                                <a href="{{ route('admin.categories.edit', $category) }}" aria-label="Edit category" title="Edit category" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/10 hover:text-primary">
                                    <x-icon name="pencil" class="size-4" />
                                </a>
                            @endcan
                            @can('categories.delete')
                                <x-confirm-form :action="route('admin.categories.destroy', $category)" method="DELETE"
                                    title="Delete category" :message="'Delete '.$category->name.'? Courses keep working but lose this grouping.'" confirm-label="Delete"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                    <x-icon name="trash" class="size-4" />
                                </x-confirm-form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">
                        <x-empty-state icon="tag" title="No categories yet" description="Create the first topic to organise your catalog." />
                    </td>
                </tr>
            @endforelse
        </tbody>
        <x-slot:footer>
            {{ $categories->links() }}
        </x-slot:footer>
    </x-table>
</x-admin.layout>
