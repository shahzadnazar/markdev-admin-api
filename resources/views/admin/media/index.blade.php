<x-admin.layout title="Media library">
    <x-page-header eyebrow="System" title="Media library" description="Files on the public disk, grouped by folder." />

    {{-- Folder tabs --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="inline-flex flex-wrap rounded-lg bg-white p-1 shadow-card">
            @foreach ($folders as $dir)
                <a href="{{ route('admin.media.index', ['folder' => $dir]) }}"
                    class="rounded-md px-4 py-2 text-sm font-medium transition {{ $folder === $dir ? 'bg-primary/10 text-primary' : 'text-on-surface-variant hover:text-on-surface' }}">
                    {{ ucfirst($dir) }}
                    <span class="ml-1 font-mono text-[11px] text-outline">{{ $counts[$dir] ?? 0 }}</span>
                </a>
            @endforeach
        </div>

        @can('media.upload')
            <form method="POST" action="{{ route('admin.media.store') }}" enctype="multipart/form-data" class="flex items-center gap-2">
                @csrf
                <input type="hidden" name="folder" value="{{ $folder }}">
                <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-primary/40 bg-white px-4 py-2.5 text-sm font-medium text-primary transition hover:border-primary hover:bg-primary/5">
                    <x-icon name="upload" class="size-4" />
                    <span>Choose files…</span>
                    <input type="file" name="files[]" multiple class="sr-only" onchange="this.form.submit()">
                </label>
            </form>
        @endcan
    </div>

    @if ($files->isEmpty())
        <x-card>
            <x-empty-state icon="photo" title="Folder is empty" description="Upload files here, or they'll appear as students and instructors add content." />
        </x-card>
    @else
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
            @foreach ($files as $file)
                <x-card :padding="false" class="group overflow-hidden">
                    <div class="flex aspect-square items-center justify-center bg-surface-ice/70">
                        @if ($file['is_image'])
                            <img src="{{ $file['url'] }}" alt="{{ $file['name'] }}" loading="lazy" class="size-full object-cover">
                        @else
                            <x-icon name="document" class="size-10 text-outline" />
                        @endif
                    </div>
                    <div class="p-3">
                        <p class="truncate text-xs font-medium text-on-surface" title="{{ $file['name'] }}">{{ $file['name'] }}</p>
                        <p class="mt-0.5 font-mono text-[10px] text-outline">
                            {{ $file['size'] >= 1048576 ? number_format($file['size'] / 1048576, 1).' MB' : number_format($file['size'] / 1024, 0).' KB' }}
                            @if ($file['modified'])
                                · {{ $file['modified']->format('M j') }}
                            @endif
                        </p>
                        <div class="mt-2 flex items-center justify-between">
                            <a href="{{ $file['url'] }}" target="_blank" rel="noreferrer"
                                class="inline-flex items-center gap-1 text-[11px] font-medium text-primary hover:underline">
                                <x-icon name="external" class="size-3" /> Open
                            </a>
                            @can('media.delete')
                                <x-confirm-form
                                    :action="route('admin.media.destroy', ['path' => $file['path']])"
                                    method="DELETE"
                                    title="Delete this file?"
                                    message="Anything referencing it will lose the file."
                                    confirm-label="Delete"
                                    class="rounded p-1 text-on-surface-variant transition hover:bg-error/10 hover:text-error"
                                    aria-label="Delete {{ $file['name'] }}"
                                >
                                    <x-icon name="trash" class="size-3.5" />
                                </x-confirm-form>
                            @endcan
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>
    @endif
    @if ($files->hasPages() || $files->total() > 0)
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-outline">
                Showing <span class="font-semibold text-on-surface">{{ $files->firstItem() ?? 0 }}–{{ $files->lastItem() ?? 0 }}</span>
                of <span class="font-semibold text-on-surface">{{ $files->total() }}</span> files
            </p>
            {{ $files->links() }}
        </div>
    @endif
</x-admin.layout>
