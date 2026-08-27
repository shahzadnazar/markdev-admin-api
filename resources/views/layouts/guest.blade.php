<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'MarkDev') }} — Admin Portal</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        {{-- Fetched without blocking first paint: a slow or unreachable font
            host would otherwise hold the whole page blank until it times out.
            Text renders in the fallback face and swaps in when the CSS lands. --}}
        <link href="https://fonts.bunny.net/css?family=hanken-grotesk:400,500,600,700|inter:400,500,600|jetbrains-mono:400,500,600&display=swap" rel="stylesheet" media="print" onload="this.media='all'; this.onload=null">
        <noscript><link href="https://fonts.bunny.net/css?family=hanken-grotesk:400,500,600,700|inter:400,500,600|jetbrains-mono:400,500,600&display=swap" rel="stylesheet"></noscript>

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-surface-ice antialiased">
        <div class="relative flex min-h-screen flex-col items-center justify-center overflow-hidden px-4 py-12">
            {{-- Ambient brand gradients --}}
            <div aria-hidden="true" class="pointer-events-none absolute -top-32 right-[-10%] size-[420px] rounded-full bg-gradient-to-br from-primary/15 to-secondary/10 blur-3xl"></div>
            <div aria-hidden="true" class="pointer-events-none absolute bottom-[-20%] left-[-8%] size-[380px] rounded-full bg-gradient-to-tr from-secondary/15 to-primary/10 blur-3xl"></div>

            <div class="relative w-full max-w-md">
                <div class="mb-8 flex flex-col items-center text-center">
                    <a href="/" class="inline-flex">
                        <x-brand-mark class="size-14" gradient-id="guest" />
                    </a>
                    <p class="mt-5 font-mono text-[11px] font-medium uppercase tracking-[0.24em] text-primary">Admin Portal</p>
                    <h1 class="mt-1.5 font-display text-2xl font-bold tracking-[-0.01em] text-on-surface">MarkDev LMS</h1>
                </div>

                <div class="rounded-2xl bg-white p-8 shadow-elevated">
                    {{ $slot }}
                </div>

                <p class="mt-6 text-center font-mono text-[10px] uppercase tracking-[0.18em] text-outline">
                    Secured area — activity is audited
                </p>
            </div>
        </div>
    </body>
</html>
