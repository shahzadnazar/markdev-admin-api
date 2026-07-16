@props(['action', 'reset' => null])

<form method="GET" action="{{ $action }}" {{ $attributes->merge(['class' => 'mb-6 flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-card']) }}>
    {{ $slot }}
    <div class="flex items-center gap-2">
        <x-btn type="submit" size="md">
            <x-icon name="funnel" class="size-4" />
            Filter
        </x-btn>
        <x-btn variant="ghost" :href="$reset ?? $action">Reset</x-btn>
    </div>
</form>
