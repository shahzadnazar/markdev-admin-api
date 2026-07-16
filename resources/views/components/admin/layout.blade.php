@props(['title' => null])

@php
$maintenance = rescue(fn () => (bool) \App\Models\Setting::query()->where('key', 'maintenance_mode')->value('value'), false, false);
$siteName = rescue(fn () => \App\Models\Setting::query()->where('key', 'site_name')->value('value'), null, false) ?: config('app.name', 'MarkDev');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title.' — ' : '' }}{{ $siteName }} Admin</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=hanken-grotesk:400,500,600,700|inter:400,500,600|jetbrains-mono:400,500,600&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-surface-ice text-on-surface antialiased">
    <div x-data="{ sidebarOpen: false }" class="min-h-screen">

        {{-- Mobile off-canvas backdrop --}}
        <div x-show="sidebarOpen" x-cloak x-transition.opacity class="fixed inset-0 z-40 bg-primary-deep/25 backdrop-blur-[2px] lg:hidden" x-on:click="sidebarOpen = false"></div>

        {{-- Sidebar --}}
        <div class="fixed inset-y-0 left-0 z-50 -translate-x-full transition-transform duration-200 lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">
            <x-admin.sidebar />
        </div>

        {{-- Main column --}}
        <div class="flex min-h-screen flex-col lg:pl-[280px]">

            {{-- Topbar --}}
            <header class="sticky top-0 z-30 border-b border-primary/5 bg-surface-ice/80 backdrop-blur">
                <div class="mx-auto flex h-16 w-full max-w-[1440px] items-center gap-4 px-4 sm:px-6 lg:px-10">
                    <button type="button" class="rounded-lg p-2 text-on-surface-variant hover:bg-white hover:text-primary lg:hidden" x-on:click="sidebarOpen = true">
                        <span class="sr-only">Open navigation</span>
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                    </button>

                    @if ($title)
                        <p class="hidden font-mono text-[11px] font-medium uppercase tracking-[0.14em] text-outline sm:block">{{ $title }}</p>
                    @endif

                    <div class="ml-auto flex items-center gap-2">
                        {{-- User menu --}}
                        <div x-data="{ open: false }" class="relative">
                            <button type="button" x-on:click="open = ! open"
                                class="flex items-center gap-2.5 rounded-full py-1 pl-1 pr-3 transition hover:bg-white hover:shadow-card">
                                <span class="flex size-8 items-center justify-center rounded-full bg-gradient-to-br from-primary to-secondary font-display text-[13px] font-semibold text-white">
                                    {{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                                </span>
                                <span class="hidden text-[13px] font-medium text-on-surface sm:block">{{ auth()->user()->name }}</span>
                                <x-icon name="chevron-down" class="size-3.5 text-outline" />
                            </button>

                            <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition
                                class="absolute right-0 mt-2 w-56 overflow-hidden rounded-xl bg-white py-1.5 shadow-elevated">
                                <div class="border-b border-surface-ice px-4 py-2.5">
                                    <p class="truncate text-[13px] font-semibold text-on-surface">{{ auth()->user()->name }}</p>
                                    <p class="truncate text-xs text-outline">{{ auth()->user()->email }}</p>
                                </div>
                                <a href="{{ route('profile.edit') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-on-surface-variant hover:bg-surface-ice hover:text-primary">
                                    <x-icon name="user-circle" class="size-4.5" /> Profile
                                </a>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="flex w-full items-center gap-2.5 px-4 py-2.5 text-left text-sm text-on-surface-variant hover:bg-surface-ice hover:text-error">
                                        <x-icon name="logout" class="size-4.5" /> Log out
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            {{-- Maintenance banner --}}
            @if ($maintenance)
                <div class="border-b border-warning/20 bg-warning-container">
                    <div class="mx-auto flex w-full max-w-[1440px] items-center gap-3 px-4 py-2.5 sm:px-6 lg:px-10">
                        <x-icon name="warning" class="size-4.5 shrink-0 text-warning" />
                        <p class="text-[13px] font-medium text-warning">
                            Maintenance mode is on — students currently see a downtime notice.
                            @can('settings.update')
                                <a href="{{ route('admin.settings.edit') }}" class="underline underline-offset-2">Manage in settings</a>
                            @endcan
                        </p>
                    </div>
                </div>
            @endif

            {{-- Page content --}}
            <main class="mx-auto w-full max-w-[1440px] flex-1 px-4 py-8 sm:px-6 lg:px-10">
                {{ $slot }}
            </main>
        </div>
    </div>

    {{-- Flash toasts --}}
    @if (session('success') || session('error'))
        <div x-data="{ shown: true }" x-show="shown" x-cloak x-init="setTimeout(() => shown = false, 5000)"
            x-transition:leave="transition ease-in duration-300"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-2"
            class="fixed bottom-6 right-6 z-[60] w-full max-w-sm">
            <div class="flex items-start gap-3 rounded-2xl bg-white p-4 shadow-elevated">
                @if (session('success'))
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-success-container text-success">
                        <x-icon name="check" class="size-4.5" />
                    </div>
                    <div class="min-w-0 pt-1">
                        <p class="font-mono text-[10px] font-medium uppercase tracking-[0.14em] text-success">Success</p>
                        <p class="mt-0.5 text-sm text-on-surface">{{ session('success') }}</p>
                    </div>
                @else
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-error-container text-error">
                        <x-icon name="warning" class="size-4.5" />
                    </div>
                    <div class="min-w-0 pt-1">
                        <p class="font-mono text-[10px] font-medium uppercase tracking-[0.14em] text-error">Error</p>
                        <p class="mt-0.5 text-sm text-on-surface">{{ session('error') }}</p>
                    </div>
                @endif
                <button type="button" class="ml-auto rounded-lg p-1 text-outline hover:bg-surface-ice hover:text-on-surface" x-on:click="shown = false">
                    <x-icon name="x-mark" class="size-4" />
                </button>
            </div>
        </div>
    @endif
</body>
</html>
