<x-admin.layout :title="$article ? 'Edit article' : 'New article'">
    <x-page-header
        eyebrow="Engagement"
        :title="$article ? 'Edit '.Str::limit($article->title, 40) : 'New help article'"
        description="Articles appear in the student portal's Help Center."
    >
        <x-slot:actions>
            <x-btn variant="ghost" :href="route('admin.help.index')">
                <x-icon name="arrow-left" class="size-4" /> Back to Help Center
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <form method="POST"
        action="{{ $article ? route('admin.help.articles.update', $article) : route('admin.help.articles.store') }}"
        class="max-w-3xl">
        @csrf
        @if ($article) @method('PUT') @endif

        <x-card class="space-y-5">
            <x-form.input label="Title" name="title" :value="$article?->title" required />

            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.select label="Category" name="help_category_id" required>
                    <option value="">Choose a category…</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(old('help_category_id', $article?->help_category_id) == $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </x-form.select>
                <x-form.input label="Slug" name="slug" :value="$article?->slug" hint="Leave blank to generate from the title." />
            </div>

            <x-form.textarea label="Excerpt" name="excerpt" :value="$article?->excerpt" rows="2"
                hint="Short summary shown in lists (max 500 characters)." />

            <x-form.textarea label="Body" name="body" :value="$article?->body" rows="12" required
                hint="Basic HTML is allowed (headings, paragraphs, lists, code blocks)." />

            <x-form.toggle label="Published" name="is_published" :checked="(bool) old('is_published', $article?->is_published ?? true)"
                hint="Drafts stay hidden from students." />
        </x-card>

        <div class="mt-6 flex items-center gap-3">
            <x-btn>
                <x-icon name="check" class="size-4" />
                {{ $article ? 'Save changes' : 'Create article' }}
            </x-btn>
            <x-btn variant="ghost" :href="route('admin.help.index')">Cancel</x-btn>
        </div>
    </form>
</x-admin.layout>
