@props(['name', 'label', 'accept', 'required' => false, 'existing' => null, 'kind' => 'any'])

@php
    $existingUrl = $existing ? \Illuminate\Support\Facades\Storage::disk('public')->url($existing) : null;
    $existingIsImage = \App\Models\StudentProfile::isImagePath($existing);
@endphp

{{--
    Document picker with live preview. Client-side it blocks files over 1 MB
    and previews images inline (PDFs get a document tile); the server enforces
    the same limits again in StudentController::validated().
--}}
<div x-data="{
        preview: null,
        fileName: null,
        fileSize: null,
        isPdf: false,
        error: null,
        pick(event) {
            this.error = null;
            const file = event.target.files[0];
            if (! file) { this.clear(); return; }
            if (file.size > 1024 * 1024) {
                this.error = 'This file is ' + (file.size / (1024 * 1024)).toFixed(2) + ' MB — the limit is 1 MB.';
                event.target.value = '';
                this.clear();
                return;
            }
            if (this.preview) URL.revokeObjectURL(this.preview);
            this.fileName = file.name;
            this.fileSize = (file.size / 1024).toFixed(0) + ' KB';
            this.isPdf = file.type === 'application/pdf';
            this.preview = this.isPdf ? null : URL.createObjectURL(file);
        },
        clear() {
            if (this.preview) URL.revokeObjectURL(this.preview);
            this.preview = null; this.fileName = null; this.fileSize = null; this.isPdf = false;
        },
    }">
    <p class="mb-1.5 text-[13px] font-medium text-on-surface">
        {{ $label }}
        @if ($required)<span class="text-error">*</span>@endif
    </p>

    <input type="file" name="{{ $name }}" id="{{ $name }}" accept="{{ $accept }}" class="sr-only"
        x-ref="input" x-on:change="pick($event)" @if ($required) required @endif>

    <button type="button" x-on:click="$refs.input.click()"
        class="group w-full rounded-xl border-2 border-dashed p-4 text-left transition
            {{ $errors->has($name) ? 'border-error/60 bg-error/[0.03]' : 'border-outline/30 hover:border-primary/50 hover:bg-primary/[0.02]' }}">

        {{-- Fresh pick: image preview --}}
        <template x-if="preview">
            <span class="flex items-center gap-3">
                <img :src="preview" alt="" class="size-14 shrink-0 rounded-lg object-cover ring-1 ring-outline/20">
                <span class="min-w-0">
                    <span class="block truncate text-sm font-medium text-on-surface" x-text="fileName"></span>
                    <span class="block font-mono text-[11px] text-success" x-text="fileSize + ' · ready to upload'"></span>
                </span>
            </span>
        </template>

        {{-- Fresh pick: PDF tile --}}
        <template x-if="isPdf">
            <span class="flex items-center gap-3">
                <span class="flex size-14 shrink-0 items-center justify-center rounded-lg bg-error/10 font-mono text-[11px] font-bold text-error">PDF</span>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-medium text-on-surface" x-text="fileName"></span>
                    <span class="block font-mono text-[11px] text-success" x-text="fileSize + ' · ready to upload'"></span>
                </span>
            </span>
        </template>

        {{-- Nothing picked yet --}}
        <template x-if="! preview && ! isPdf">
            <span class="flex items-center gap-3">
                @if ($existingUrl && $existingIsImage)
                    <img src="{{ $existingUrl }}" alt="" class="size-14 shrink-0 rounded-lg object-cover ring-1 ring-outline/20">
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-on-surface">Current file on record</span>
                        <span class="block text-xs text-outline">Click to replace (max 1 MB)</span>
                    </span>
                @elseif ($existingUrl)
                    <span class="flex size-14 shrink-0 items-center justify-center rounded-lg bg-error/10 font-mono text-[11px] font-bold text-error">PDF</span>
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-on-surface">Current file on record</span>
                        <span class="block text-xs text-outline">Click to replace (max 1 MB)</span>
                    </span>
                @else
                    <span class="flex size-14 shrink-0 items-center justify-center rounded-lg bg-primary/8 text-primary transition group-hover:bg-primary/15">
                        <x-icon name="upload" class="size-5" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-on-surface">Click to upload</span>
                        <span class="block text-xs text-outline">{{ $kind === 'image' ? 'JPG, PNG or WEBP' : 'JPG, PNG, WEBP or PDF' }} · max 1 MB</span>
                    </span>
                @endif
            </span>
        </template>
    </button>

    <p x-show="error" x-cloak class="mt-1.5 text-xs font-medium text-error" x-text="error"></p>
    @if ($existingUrl)
        <a href="{{ $existingUrl }}" target="_blank" class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
            <x-icon name="external" class="size-3.5" /> View current file
        </a>
    @endif
    <x-form.error :name="$name" />
</div>
