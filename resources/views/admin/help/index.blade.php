<x-admin.layout title="Help Center">
    <x-page-header eyebrow="Engagement" title="Help Center" description="Guides, categories and FAQs shown in the student portal.">
        <x-slot:actions>
            @can('help.manage')
                <x-btn :href="route('admin.help.articles.create')">
                    <x-icon name="plus" class="size-4" /> New article
                </x-btn>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Tabs --}}
    <div class="mb-6 inline-flex rounded-lg bg-white p-1 shadow-card">
        @foreach (['articles' => 'Articles', 'categories' => 'Categories', 'faqs' => 'FAQs'] as $key => $label)
            <a href="{{ route('admin.help.index', ['tab' => $key]) }}"
                class="rounded-md px-4 py-2 text-sm font-medium transition {{ $tab === $key ? 'bg-primary/10 text-primary' : 'text-on-surface-variant hover:text-on-surface' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    @if ($tab === 'articles')
        <x-filter-bar :action="route('admin.help.index')">
            <input type="hidden" name="tab" value="articles">
            <div class="w-full sm:w-72">
                <x-form.label for="search" value="Search" />
                <input type="search" name="search" id="search" value="{{ request('search') }}" placeholder="Article title…" class="field">
            </div>
        </x-filter-bar>

        <x-table>
            <thead class="bg-surface-ice/60">
                <tr>
                    <th class="th">Article</th>
                    <th class="th">Category</th>
                    <th class="th">Status</th>
                    <th class="th">Updated</th>
                    <th class="th text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($articles as $article)
                    <tr class="row">
                        <td class="td max-w-[24rem]">
                            <p class="truncate font-medium text-on-surface">{{ $article->title }}</p>
                            @if ($article->excerpt)
                                <p class="truncate text-xs text-outline">{{ $article->excerpt }}</p>
                            @endif
                        </td>
                        <td class="td">
                            @if ($article->category)
                                <x-badge variant="primary">{{ $article->category->name }}</x-badge>
                            @else
                                <span class="text-xs text-outline">—</span>
                            @endif
                        </td>
                        <td class="td">
                            <x-badge :variant="$article->is_published ? 'success' : 'neutral'">{{ $article->is_published ? 'published' : 'draft' }}</x-badge>
                        </td>
                        <td class="td font-mono text-xs text-outline">{{ $article->updated_at?->format('M j, Y') }}</td>
                        <td class="td text-right">
                            <div class="inline-flex items-center gap-1">
                                @can('help.manage')
                                    <x-btn variant="ghost" size="sm" :href="route('admin.help.articles.edit', $article)" aria-label="Edit article" title="Edit article">
                                        <x-icon name="pencil" class="size-4" />
                                    </x-btn>
                                    <x-confirm-form
                                        :action="route('admin.help.articles.destroy', $article)"
                                        method="DELETE"
                                        title="Delete this article?"
                                        message="Students will no longer find it in the Help Center."
                                        confirm-label="Delete"
                                        class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error"
                                        aria-label="Delete article"
                                    >
                                        <x-icon name="trash" class="size-4" />
                                    </x-confirm-form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty-state icon="lifebuoy" title="No articles" description="Write your first help article for students." /></td></tr>
                @endforelse
            </tbody>
            @if ($articles->hasPages())
                <x-slot:footer>{{ $articles->links() }}</x-slot:footer>
            @endif
        </x-table>
    @elseif ($tab === 'categories')
        <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
            <x-table>
                <thead class="bg-surface-ice/60">
                    <tr>
                        <th class="th">Category</th>
                        <th class="th">Slug</th>
                        <th class="th">Articles</th>
                        <th class="th">Position</th>
                        <th class="th text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($categories as $category)
                        <tr class="row">
                            <td class="td font-medium text-on-surface">{{ $category->name }}</td>
                            <td class="td font-mono text-xs text-outline">{{ $category->slug }}</td>
                            <td class="td font-mono text-xs text-on-surface-variant">{{ $category->articles_count }}</td>
                            <td class="td font-mono text-xs text-outline">{{ $category->position }}</td>
                            <td class="td text-right">
                                <div class="inline-flex items-center gap-1">
                                    @can('help.manage')
                                        <button type="button" x-data x-on:click="$dispatch('open-modal', 'edit-category-{{ $category->id }}')"
                                            class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/5 hover:text-primary" aria-label="Edit category">
                                            <x-icon name="pencil" class="size-4" />
                                        </button>
                                        <x-confirm-form
                                            :action="route('admin.help.categories.destroy', $category)"
                                            method="DELETE"
                                            title="Delete this category?"
                                            message="It must be empty — move its articles first."
                                            confirm-label="Delete"
                                            class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error"
                                            aria-label="Delete category"
                                        >
                                            <x-icon name="trash" class="size-4" />
                                        </x-confirm-form>
                                    @endcan
                                </div>
                            </td>
                        </tr>

                        <x-modal :name="'edit-category-'.$category->id" max-width="md">
                            <form method="POST" action="{{ route('admin.help.categories.update', $category) }}" class="space-y-4 p-6">
                                @csrf
                                @method('PUT')
                                <h3 class="font-display text-lg font-semibold text-on-surface">Edit category</h3>
                                <x-form.input label="Name" name="name" :value="$category->name" required />
                                <x-form.input label="Slug" name="slug" :value="$category->slug" hint="Leave blank to regenerate." />
                                <x-form.input type="number" label="Position" name="position" :value="$category->position" min="0" />
                                <div class="flex justify-end gap-3">
                                    <x-btn type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'edit-category-{{ $category->id }}')">Cancel</x-btn>
                                    <x-btn><x-icon name="check" class="size-4" /> Save</x-btn>
                                </div>
                            </form>
                        </x-modal>
                    @empty
                        <tr><td colspan="5"><x-empty-state icon="tag" title="No categories" description="Group your help articles into categories." /></td></tr>
                    @endforelse
                </tbody>
            </x-table>

            @can('help.manage')
                <x-card>
                    <h2 class="font-display text-lg font-semibold text-on-surface">New category</h2>
                    <form method="POST" action="{{ route('admin.help.categories.store') }}" class="mt-4 space-y-4">
                        @csrf
                        <x-form.input label="Name" name="name" required />
                        <x-form.input type="number" label="Position" name="position" value="0" min="0" />
                        <x-btn class="w-full"><x-icon name="plus" class="size-4" /> Add category</x-btn>
                    </form>
                </x-card>
            @endcan
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
            <div class="space-y-3">
                @forelse ($faqs as $faq)
                    <x-card class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-medium text-on-surface">{{ $faq->question }}</p>
                                @unless ($faq->is_published)
                                    <x-badge variant="neutral">draft</x-badge>
                                @endunless
                            </div>
                            <p class="mt-1.5 text-sm leading-6 text-on-surface-variant">{{ $faq->answer }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            @can('help.manage')
                                <button type="button" x-data x-on:click="$dispatch('open-modal', 'edit-faq-{{ $faq->id }}')"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-primary/5 hover:text-primary" aria-label="Edit FAQ">
                                    <x-icon name="pencil" class="size-4" />
                                </button>
                                <x-confirm-form
                                    :action="route('admin.help.faqs.destroy', $faq)"
                                    method="DELETE"
                                    title="Delete this FAQ?"
                                    confirm-label="Delete"
                                    class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error"
                                    aria-label="Delete FAQ"
                                >
                                    <x-icon name="trash" class="size-4" />
                                </x-confirm-form>
                            @endcan
                        </div>
                    </x-card>

                    <x-modal :name="'edit-faq-'.$faq->id" max-width="lg">
                        <form method="POST" action="{{ route('admin.help.faqs.update', $faq) }}" class="space-y-4 p-6">
                            @csrf
                            @method('PUT')
                            <h3 class="font-display text-lg font-semibold text-on-surface">Edit FAQ</h3>
                            <x-form.input label="Question" name="question" :value="$faq->question" required />
                            <x-form.textarea label="Answer" name="answer" :value="$faq->answer" rows="4" required />
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-form.input type="number" label="Position" name="position" :value="$faq->position" min="0" />
                                <x-form.toggle label="Published" name="is_published" :checked="(bool) $faq->is_published" />
                            </div>
                            <div class="flex justify-end gap-3">
                                <x-btn type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'edit-faq-{{ $faq->id }}')">Cancel</x-btn>
                                <x-btn><x-icon name="check" class="size-4" /> Save</x-btn>
                            </div>
                        </form>
                    </x-modal>
                @empty
                    <x-card><x-empty-state icon="lifebuoy" title="No FAQs" description="Answer the questions students ask most." /></x-card>
                @endforelse
            </div>

            @can('help.manage')
                <x-card class="self-start">
                    <h2 class="font-display text-lg font-semibold text-on-surface">New FAQ</h2>
                    <form method="POST" action="{{ route('admin.help.faqs.store') }}" class="mt-4 space-y-4">
                        @csrf
                        <x-form.input label="Question" name="question" required />
                        <x-form.textarea label="Answer" name="answer" rows="4" required />
                        <x-form.toggle label="Published" name="is_published" :checked="true" />
                        <x-btn class="w-full"><x-icon name="plus" class="size-4" /> Add FAQ</x-btn>
                    </form>
                </x-card>
            @endcan
        </div>
    @endif
</x-admin.layout>
