@props([
    'name',
    'label' => null,
    'id' => null,
    'accept' => null,
    'acceptLabel' => null,
    'maxKb' => null,
    'required' => false,
    'multiple' => false,
    'hint' => null,
    'existing' => null,
    'existingLabel' => 'Current file on record',
    'existingIsImage' => false,
    'preview' => false,
    'errorName' => null,
])

@php
    use App\Support\UploadLimits;
    use Illuminate\Support\Str;

    // `attachments[]` is the posted name; `attachments` is the id and the key
    // the validator reports against.
    $field = Str::before($name, '[');
    $id ??= $field;
    $errorName ??= $multiple ? $field.'.*' : $field;

    // What the SERVER will really take, not what the rule says on its own —
    // see UploadLimits. A chip promising 20 MB where php.ini stops at 2 is a
    // promise nothing downstream can keep.
    $maxBytes = UploadLimits::maxBytes($maxKb);
    $maxLabel = UploadLimits::maxLabel($maxKb);
@endphp

{{--
    Drag a file in, or click to browse.

    There is a genuine <input type="file"> underneath, sr-only but present. It
    is what makes this a form control rather than a decorated div: the form
    posts normally with no JavaScript and no controller change, `required` is
    enforced by the browser, the field keeps its place in the tab order with a
    visible focus ring, and a screen reader announces a file input. The zone is
    a <label for> pointing at it, so a click anywhere opens the picker with no
    handler to keep in step.

    ONE input, and it lives outside every <template x-if>. Alpine removes and
    re-creates the contents of a template when its condition flips, so an input
    inside one is a different element each time: $refs.input would follow the
    new node and a FileList assigned on drop would go with the discarded one.
    The React side of this had exactly that bug, measured — the card showed the
    file and the input carried nothing. Keep the input out here.

    The client-side message is a courtesy, never the rule. Everything it lets
    through still meets the validator, which is where required, mimes and max
    actually live.
--}}
<div x-data="{
        dragging: false,
        error: null,
        picked: [],
        accept: @js($accept),
        max: {{ $maxBytes }},
        {{-- The chip's exact wording, so the message and the chip cannot
             disagree about the same number ('Max 2 MB' / 'the limit is 2.0 MB'). --}}
        maxLabel: @js($maxLabel),
        multiple: @js((bool) $multiple),

        /** A friendly reason this file will not do, or null if it will. */
        reject(file) {
            if (this.max > 0 && file.size > this.max) {
                return file.name + ' is ' + this.size(file.size) + ' — the limit is ' + this.maxLabel + '.';
            }
            if (! this.accept) return null;
            const patterns = this.accept.split(',').map(p => p.trim().toLowerCase()).filter(Boolean);
            const name = file.name.toLowerCase();
            const type = (file.type || '').toLowerCase();
            const ok = patterns.some(p => p.startsWith('.')
                ? name.endsWith(p)
                : p.endsWith('/*')
                    ? type.startsWith(p.slice(0, -1))
                    : type === p);
            return ok ? null : file.name + ' is not a file type this accepts.';
        },

        size(bytes) {
            if (bytes < 1024) return bytes + ' B';
            const units = ['KB', 'MB', 'GB'];
            let value = bytes / 1024, unit = 0;
            while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++; }
            return (value < 10 ? value.toFixed(1) : Math.round(value)) + ' ' + units[unit];
        },

        /**
         * Adopt a FileList, keeping only what passes and telling the user why.
         *
         * `mode` is load-bearing, not decoration. A DROP hands us files the
         * input has never seen, so on a multiple zone they ADD to what is
         * already chosen. A CHANGE hands us files the browser has ALREADY
         * written onto the input, replacing whatever was there — so on a
         * change the browser's list IS the new selection.
         *
         * One path cannot be right for both. It was written as an append, and
         * Browse counted every file twice: once from the input, once from the
         * argument. Not merely a doubled display — commit() writes the list
         * back onto the input, so the form posted two copies and the server
         * stored two.
         *
         * Anything that is neither reads as 'change', because replacing is the
         * answer that cannot silently duplicate.
         */
        take(list, mode) {
            const incoming = Array.from(list ?? []);
            const appending = mode === 'drop' && this.multiple;

            // A drop of nothing is nothing. A change to nothing is the browser
            // clearing the field, and the cards have to follow it.
            if (incoming.length === 0 && mode === 'drop') return;

            const kept = [];
            const refused = [];
            for (const file of incoming) {
                const reason = this.reject(file);
                reason ? refused.push(reason) : kept.push(file);
            }

            // A file the client refuses must not stay on the input either, or
            // the form would post it anyway behind the message saying it is no
            // good and lean on the server to say so twice.
            this.error = refused.length ? refused.join(' ') : null;

            // On a drop the input's own files are still ours to keep; on a
            // change the browser has already discarded them.
            const base = appending ? this.files() : [];
            this.commit(this.multiple ? [...base, ...kept] : kept.slice(0, 1));
        },

        /** The files currently on the real input. */
        files() {
            return Array.from(this.$refs.input.files ?? []);
        },

        /**
         * Write a list back onto the input.
         *
         * A FileList is read-only, so removing one of several means rebuilding
         * it through a DataTransfer — the input is the source of truth and the
         * cards are drawn from it, never the other way round.
         */
        commit(files) {
            const data = new DataTransfer();
            for (const file of files) data.items.add(file);
            this.$refs.input.files = data.files;
            this.render();
        },

        remove(index) {
            const files = this.files();
            files.splice(index, 1);
            this.error = null;
            this.commit(files);
        },

        render() {
            for (const card of this.picked) {
                if (card.url) URL.revokeObjectURL(card.url);
            }
            this.picked = this.files().map(file => ({
                name: file.name,
                size: this.size(file.size),
                url: {{ $preview ? 'true' : 'false' }} && file.type.startsWith('image/')
                    ? URL.createObjectURL(file)
                    : null,
            }));
        },
    }"
    x-on:drop.prevent="dragging = false; take($event.dataTransfer.files, 'drop')"
    x-on:dragover.prevent="dragging = true"
    x-on:dragleave="dragging = false">

    @if ($label)
        <x-form.label :for="$id" :value="$label" :required="$required" />
    @endif

    <label for="{{ $id }}"
        class="flex w-full cursor-pointer flex-col gap-3 rounded-xl border-2 border-dashed p-4 transition
            has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-primary/25"
        :class="dragging
            ? 'border-primary bg-primary/[0.06]'
            : '{{ $errors->has($errorName) ? 'border-error/60 bg-error/[0.03]' : 'border-outline/30 hover:border-primary/50 hover:bg-primary/[0.02]' }}'">

        {{-- The one real control. Never inside a template — see the note above. --}}
        <input type="file" name="{{ $name }}" id="{{ $id }}" x-ref="input" class="sr-only"
            @if ($accept) accept="{{ $accept }}" @endif
            @if ($multiple) multiple @endif
            @required($required)
            @if ($errors->has($errorName)) aria-invalid="true" @endif
            aria-describedby="{{ $id }}-chips"
            x-on:change="take($event.target.files, 'change')">

        {{-- Files chosen just now, drawn from the input itself. --}}
        <template x-for="(card, index) in picked" :key="index">
            <span class="flex items-center gap-3">
                <template x-if="card.url">
                    <img :src="card.url" alt="" class="size-14 shrink-0 rounded-lg object-cover ring-1 ring-outline/20">
                </template>
                <template x-if="! card.url">
                    <span class="flex size-14 shrink-0 items-center justify-center rounded-lg bg-primary/8 text-primary">
                        <x-icon name="document" class="size-5" />
                    </span>
                </template>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-on-surface" x-text="card.name"></span>
                    <span class="block font-mono text-[11px] text-success" x-text="card.size + ' · ready to upload'"></span>
                </span>
                {{-- A button is interactive content, so the label's activation
                     behaviour skips it and this does not also open the picker.
                     .prevent says so out loud rather than relying on the rule. --}}
                <button type="button" x-on:click.prevent="remove(index)"
                    class="shrink-0 rounded-lg p-1.5 text-on-surface-variant transition hover:bg-error/10 hover:text-error"
                    :aria-label="'Remove ' + card.name">
                    <x-icon name="x-mark" class="size-4" />
                </button>
            </span>
        </template>

        {{-- Nothing chosen: the file already on record, or the empty zone. --}}
        <template x-if="picked.length === 0">
            <span class="flex items-center gap-3">
                @if ($existing && $existingIsImage)
                    <img src="{{ $existing }}" alt="" class="size-14 shrink-0 rounded-lg object-cover ring-1 ring-outline/20">
                @elseif ($existing)
                    <span class="flex size-14 shrink-0 items-center justify-center rounded-lg bg-primary/8 text-primary">
                        <x-icon name="document" class="size-5" />
                    </span>
                @else
                    <span class="flex size-14 shrink-0 items-center justify-center rounded-lg bg-primary/8 text-primary">
                        <x-icon name="upload" class="size-5" />
                    </span>
                @endif
                <span class="min-w-0">
                    <span class="block text-sm font-medium text-on-surface">
                        @if ($existing)
                            {{ $existingLabel }} — drop a file here to replace it
                        @else
                            Drop {{ $multiple ? 'files' : 'a file' }} here — or <span class="text-primary underline underline-offset-2">Browse</span>
                        @endif
                    </span>
                    <span id="{{ $id }}-chips" class="mt-1 flex flex-wrap items-center gap-1.5">
                        <span class="rounded-full bg-surface-ice px-2 py-0.5 font-mono text-[11px] text-on-surface-variant">{{ $acceptLabel ?? 'Any file' }}</span>
                        <span class="rounded-full bg-surface-ice px-2 py-0.5 font-mono text-[11px] text-on-surface-variant">Max {{ $maxLabel }}</span>
                    </span>
                </span>
            </span>
        </template>
    </label>

    <p x-show="error" x-cloak class="mt-1.5 text-xs font-medium text-error" x-text="error" role="status"></p>

    @if ($existing)
        <a href="{{ $existing }}" target="_blank" class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
            <x-icon name="external" class="size-3.5" /> View current file
        </a>
    @endif

    {{-- The slot is the hint when it needs markup of its own (a column list,
         a link); the `hint` prop covers the plain-text majority. --}}
    @if (trim($slot) !== '')
        <p class="mt-1.5 text-xs text-outline">{{ $slot }}</p>
    @elseif ($hint)
        <p class="mt-1.5 text-xs text-outline">{{ $hint }}</p>
    @endif

    <x-form.error :name="$errorName" />
</div>
