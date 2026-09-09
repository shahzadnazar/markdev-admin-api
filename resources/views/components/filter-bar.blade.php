@props(['action', 'reset' => null, 'results' => null])

{{-- `results` is the id of the container this bar narrows. Given one, typing
     re-fetches just that container (resources/js/live-search.js); the Filter
     button below is untouched either way and still submits the form normally.
     Without one the bar behaves exactly as it always did. --}}
<form method="GET" action="{{ $action }}"
    @if ($results) data-live-search="#{{ $results }}" @endif
    {{ $attributes->merge(['class' => 'mb-6 flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-card']) }}>
    {{ $slot }}
    <div class="flex items-center gap-2">
        <x-btn type="submit" size="md">
            <x-icon name="funnel" class="size-4" />
            Filter
        </x-btn>
        <x-btn variant="ghost" :href="$reset ?? $action">Reset</x-btn>
    </div>
</form>
