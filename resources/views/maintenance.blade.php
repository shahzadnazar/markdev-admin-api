{{-- Shown to whoever is held out while maintenance mode is on: a client here,
     and a student only if they ever reach a web route — the portal is React and
     gets the JSON 503 instead.

     The message is the ADMIN'S, passed in by the middleware. Nothing user-facing
     is baked into this file; App\Support\MaintenanceMode::DEFAULT_MESSAGE is
     what an admin who has typed nothing falls back to. --}}
<x-guest-layout eyebrow="Scheduled maintenance" heading="MarkDev">
    <div class="text-center">
        <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-warning-container text-warning">
            <x-icon name="cog" class="size-7" />
        </div>

        <h2 class="mt-4 font-display text-lg font-semibold text-on-surface">We'll be back shortly</h2>

        <p class="mt-2 text-[13px] leading-5 text-on-surface-variant">{{ $message }}</p>

        @auth
            {{-- A way out that is not the back button. Their session is fine;
                 the academy is simply not open to them this minute. --}}
            <form method="POST" action="{{ route('logout') }}" class="mt-7">
                @csrf
                <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-outline-variant bg-white px-4 py-2.5 text-sm font-medium text-on-surface-variant transition hover:border-primary hover:text-primary">
                    Log out
                </button>
            </form>
        @endauth
    </div>
</x-guest-layout>
