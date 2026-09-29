@props(['title' => null, 'client' => null])

{{-- THE CLIENT PORTAL'S OWN SHELL.

     Deliberately not components/admin/layout, and deliberately not a version of
     it with the panel parts behind an @if. It has no sidebar, no notification
     bell, no announcement ticker, no live search and no maintenance banner —
     not because those are hidden from a client, but because they are not here.
     A branch that hides a panel control from a client is a branch somebody can
     get backwards; an absent control cannot be shown by mistake.

     What IS shared with the panel are components that receive only what they
     display and know nothing about who is looking: x-card, x-page-header,
     x-badge, x-icon, x-empty-state. --}}
@php
    $siteName = \App\Models\Setting::cached('site_name') ?: config('app.name', 'MarkDev');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title.' — ' : '' }}{{ $siteName }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    {{-- Fetched without blocking first paint, as everywhere else: a slow font
         host would otherwise hold the page blank until it timed out. --}}
    <link href="https://fonts.bunny.net/css?family=hanken-grotesk:400,500,600,700|inter:400,500,600|jetbrains-mono:400,500,600&display=swap" rel="stylesheet" media="print" onload="this.media='all'; this.onload=null">
    <noscript><link href="https://fonts.bunny.net/css?family=hanken-grotesk:400,500,600,700|inter:400,500,600|jetbrains-mono:400,500,600&display=swap" rel="stylesheet"></noscript>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-surface-ice text-on-surface antialiased">
    <div class="flex min-h-screen flex-col">
        <header class="border-b border-primary/5 bg-white">
            <div class="mx-auto flex h-16 w-full max-w-5xl items-center gap-4 px-4 sm:px-6">
                <a href="{{ route('client.projects.index') }}" class="flex items-center gap-3">
                    <x-brand-mark class="size-9 shrink-0" gradient-id="client" />
                    <span class="leading-tight">
                        <span class="block font-display text-[15px] font-bold tracking-[-0.01em] text-on-surface">{{ $siteName }}</span>
                        <span class="block font-mono text-[10px] font-medium uppercase tracking-[0.14em] text-primary">Client Portal</span>
                    </span>
                </a>

                <div class="ml-auto flex items-center gap-4">
                    {{-- The client's own name, and nothing that could be another
                         client's. No bell: a client has no notifications at all,
                         and an empty bell would only invite somebody to fill it. --}}
                    <span class="hidden text-right leading-tight sm:block">
                        <span class="block text-[13px] font-medium text-on-surface">{{ $client?->company ?: $client?->name ?: auth()->user()->name }}</span>
                        <span class="block font-mono text-[10px] text-outline">{{ auth()->user()->email }}</span>
                    </span>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-outline-variant bg-white px-3 py-2 text-[13px] font-medium text-on-surface-variant transition hover:border-primary hover:text-primary">
                            <x-icon name="logout" class="size-4" /> Log out
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-8 sm:px-6">
            {{ $slot }}
        </main>

        <footer class="border-t border-primary/5 px-4 py-6 text-center">
            <p class="font-mono text-[10px] uppercase tracking-[0.14em] text-outline">{{ $siteName }} · Client Portal</p>
        </footer>
    </div>

    {{-- Flash messages. The panel's toast stack is Alpine and lives in its own
         layout; this is the same information without the machinery. --}}
    @foreach (['success' => 'success', 'error' => 'error'] as $key => $tone)
        @if (session()->has($key))
            <div role="status" class="fixed bottom-6 left-1/2 z-50 w-[min(28rem,calc(100vw-2rem))] -translate-x-1/2 rounded-2xl bg-white p-4 shadow-elevated">
                <div class="flex items-start gap-3">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full {{ $tone === 'success' ? 'bg-success-container text-success' : 'bg-error-container text-error' }}">
                        <x-icon :name="$tone === 'success' ? 'check' : 'warning'" class="size-4.5" />
                    </div>
                    <p class="pt-1.5 text-sm text-on-surface">{{ session($key) }}</p>
                </div>
            </div>
        @endif
    @endforeach
</body>
</html>
