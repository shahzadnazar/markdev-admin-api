@props(['gradientId' => 'default'])

{{-- MarkDev gradient M-wave mark. --}}
<svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" {{ $attributes }}>
    <defs>
        <linearGradient id="mdw-{{ $gradientId ?? 'default' }}" x1="4" y1="40" x2="44" y2="8" gradientUnits="userSpaceOnUse">
            <stop stop-color="#124389" />
            <stop offset="1" stop-color="#6B53C4" />
        </linearGradient>
    </defs>
    <rect x="2" y="2" width="44" height="44" rx="12" fill="url(#mdw-{{ $gradientId ?? 'default' }})" />
    <path d="M10 33V16.5c0-1.2 1.5-1.8 2.4-.9l8.1 8.6c.8.85 2.2.85 3 0l8.1-8.6c.9-.9 2.4-.3 2.4.9V33"
        stroke="white" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round" fill="none" />
</svg>
