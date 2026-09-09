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
                <td class="td font-mono text-xs text-outline">{{ $article->updated_at?->format('j M Y') }}</td>
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
        <x-slot:footer><div data-pagination>{{ $articles->links() }}</div></x-slot:footer>
    @endif
</x-table>
