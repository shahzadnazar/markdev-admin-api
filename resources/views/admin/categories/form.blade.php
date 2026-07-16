<x-admin.layout :title="$category ? 'Edit category' : 'New category'">
    <x-page-header
        eyebrow="Learning"
        :title="$category ? 'Edit '.$category->name : 'New category'"
        description="Categories group courses in the student catalog."
    >
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.categories.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to categories
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ $category ? route('admin.categories.update', $category) : route('admin.categories.store') }}" class="max-w-2xl">
        @csrf
        @if ($category) @method('PUT') @endif

        <x-card class="space-y-5">
            <x-form.input label="Name" name="name" :value="$category?->name" required />
            <x-form.input label="Slug" name="slug" :value="$category?->slug" hint="Leave blank to generate from the name." />
            <x-form.textarea label="Description" name="description" :value="$category?->description" rows="4" />
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn>
                <x-icon name="check" class="size-4" />
                {{ $category ? 'Save changes' : 'Create category' }}
            </x-btn>
            <x-btn variant="ghost" :href="route('admin.categories.index')">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
