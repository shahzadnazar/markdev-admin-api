<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 — {{ config('app.name', 'MarkDev') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    {{-- Fetched without blocking first paint: a slow or unreachable font
        host would otherwise hold the whole page blank until it times out.
        Text renders in the fallback face and swaps in when the CSS lands. --}}
    <link href="https://fonts.bunny.net/css?family=hanken-grotesk:400,500,600,700|inter:400,500,600|jetbrains-mono:400,500,600&display=swap" rel="stylesheet" media="print" onload="this.media='all'; this.onload=null">
    <noscript><link href="https://fonts.bunny.net/css?family=hanken-grotesk:400,500,600,700|inter:400,500,600|jetbrains-mono:400,500,600&display=swap" rel="stylesheet"></noscript>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-surface-ice p-6">
    <div class="w-full max-w-md rounded-2xl bg-white p-10 text-center shadow-card">
        <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-error-container text-error">
            <svg class="size-7" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636" /></svg>
        </div>
        <p class="mt-6 font-mono text-[11px] font-medium uppercase tracking-[0.2em] text-error">Error 403</p>
        <h1 class="mt-2 font-display text-2xl font-bold tracking-[-0.01em] text-on-surface">Access restricted</h1>
        <p class="mt-2 text-sm leading-6 text-on-surface-variant">
            {{ $exception?->getMessage() ?: "Your account doesn't have permission to view this area of the MarkDev admin portal." }}
        </p>

        <div class="mt-8 flex flex-col items-center gap-3">
            @auth
                @if (auth()->user()->hasAnyRole(['super-admin', 'admin', 'manager', 'instructor']))
                    <a href="{{ route('admin.dashboard') }}" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-white shadow-card transition hover:bg-primary-deep">
                        Back to dashboard
                    </a>
                @endif
                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-outline-variant bg-white px-4 py-2.5 text-sm font-medium text-on-surface-variant transition hover:border-primary hover:text-primary">
                        Log out
                    </button>
                </form>
            @else
                <a href="{{ route('login') }}" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-white shadow-card transition hover:bg-primary-deep">
                    Go to login
                </a>
            @endauth
        </div>
    </div>
</body>
</html>
