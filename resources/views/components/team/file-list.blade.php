@props(['owner', 'ownerType', 'files'])

@php
    use App\Models\TeamFile;
    use App\Support\PrivateFiles;
    use App\Support\UploadLimits;

    $viewer = auth()->user();
    $mayDecideVisibility = $viewer?->can('clients.view') ?? false;
@endphp

{{-- Files on a project or a task.

     ALWAYS on the private disk and always served through FileController, which
     asks whether this viewer can see the work before streaming a byte. The
     link below is signed so it carries WHO is asking; it is never what decides
     whether they may look.

     The upload control is x-form.dropzone — the one this codebase already has.
     There is no second dropzone here. --}}
<x-card>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h2 class="font-display text-[15px] font-semibold text-on-surface">Files</h2>
            <p class="mt-0.5 text-[13px] text-on-surface-variant">
                Images up to {{ UploadLimits::maxLabel(TeamFile::IMAGE_MAX_KB) }}, other files up to {{ UploadLimits::maxLabel(TeamFile::FILE_MAX_KB) }}.
                Stored privately — never on a public URL.
            </p>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.team-files.store', [$ownerType, $owner->id]) }}" enctype="multipart/form-data" class="mt-4">
        @csrf
        <x-form.dropzone name="file" :max-kb="TeamFile::FILE_MAX_KB"
            accept-label="Images, documents and zips"
            hint="An image is held to the tighter limit above." />
        <x-btn class="mt-3" size="sm"><x-icon name="upload" class="size-4" /> Upload</x-btn>
    </form>

    <div class="mt-5 space-y-2">
        @forelse ($files as $file)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-outline-variant/60 px-4 py-3">
                <div class="flex min-w-0 items-center gap-3">
                    <x-icon :name="$file->isImage() ? 'photo' : 'document'" class="size-4 shrink-0 text-outline" />
                    <div class="min-w-0">
                        <a href="{{ PrivateFiles::signedUrl('files.team', ['file' => $file->id], $viewer) }}"
                            class="block truncate text-[13px] font-medium text-on-surface hover:text-primary">{{ $file->original_name }}</a>
                        <p class="mt-0.5 font-mono text-[11px] text-outline">
                            {{ $file->sizeLabel() }} · {{ $file->uploader?->name ?? 'Unknown' }} · {{ $file->created_at?->format('j M Y') }}
                        </p>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-1.5">
                    @if ($mayDecideVisibility)
                        {{-- ADMIN ONLY. A lead and a member may upload and may
                             not decide what a client sees — the same answer
                             milestones give, so the two agree. --}}
                        <form method="POST" action="{{ route('admin.team-files.visibility', $file) }}">
                            @csrf
                            <input type="hidden" name="is_client_visible" value="{{ $file->is_client_visible ? '0' : '1' }}">
                            <button type="submit"
                                class="rounded-lg px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-[0.06em] transition {{ $file->is_client_visible ? 'bg-primary/10 text-primary' : 'bg-on-surface-variant/10 text-on-surface-variant hover:bg-primary/10 hover:text-primary' }}">
                                {{ $file->is_client_visible ? 'Client can see' : 'Internal' }}
                            </button>
                        </form>
                    @endif

                    @if ($file->mayBeDeletedBy($viewer))
                        <x-confirm-form :action="route('admin.team-files.destroy', $file)" method="DELETE"
                            title="Remove file"
                            :message="'Remove '.$file->original_name.'? The record is soft-deleted and the stored file is kept — a restored record without its bytes is worse than one that takes disk space.'"
                            confirm-label="Remove"
                            class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                            <x-icon name="trash" class="size-4" />
                        </x-confirm-form>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-[13px] text-on-surface-variant">No files yet.</p>
        @endforelse
    </div>
</x-card>
