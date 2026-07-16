@props([
    'action',
    'method' => 'POST',
    'title' => 'Are you sure?',
    'message' => 'This action cannot be undone.',
    'confirmLabel' => 'Confirm',
    'variant' => 'danger',
])

{{--
    A form whose submit button first opens a premium confirm dialog.
    The trigger button is the default slot.
--}}
<div x-data="{ confirming: false }" class="inline-flex">
    <button type="button" x-on:click="confirming = true" {{ $attributes }}>
        {{ $slot }}
    </button>

    <template x-teleport="body">
        <div x-show="confirming" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
            x-on:keydown.escape.window="confirming = false">
            <div x-show="confirming" x-transition.opacity class="absolute inset-0 bg-primary-deep/20 backdrop-blur-[2px]" x-on:click="confirming = false"></div>
            <div x-show="confirming" x-transition class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-elevated">
                <div class="flex items-start gap-4">
                    <div class="flex size-11 shrink-0 items-center justify-center rounded-xl {{ $variant === 'danger' ? 'bg-error-container text-error' : 'bg-primary/10 text-primary' }}">
                        <x-icon name="warning" class="size-5" />
                    </div>
                    <div class="min-w-0">
                        <h3 class="font-display text-lg font-semibold text-on-surface">{{ $title }}</h3>
                        <p class="mt-1 text-sm leading-6 text-on-surface-variant">{{ $message }}</p>
                    </div>
                </div>
                <form method="POST" action="{{ $action }}" class="mt-6 flex justify-end gap-3">
                    @csrf
                    @if (strtoupper($method) !== 'POST')
                        @method($method)
                    @endif
                    <x-btn type="button" variant="ghost" x-on:click="confirming = false">Cancel</x-btn>
                    <x-btn type="submit" :variant="$variant === 'danger' ? 'danger' : 'primary'">{{ $confirmLabel }}</x-btn>
                </form>
            </div>
        </div>
    </template>
</div>
