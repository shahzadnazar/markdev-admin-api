<x-admin.layout>
    @isset($header)
        <div class="mb-8">{{ $header }}</div>
    @endisset

    {{ $slot }}
</x-admin.layout>
