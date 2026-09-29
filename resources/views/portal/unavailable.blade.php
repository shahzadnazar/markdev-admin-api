{{-- Signed in, and there is nothing yet to sign in to.

     Reached from PortalHome::NONE: a client today, and any role a later phase
     adds before the phase that builds its screens. The account is fine, which
     is the whole message — a 403 here would say the opposite. --}}
<x-guest-layout eyebrow="Access pending" heading="MarkDev">
    <div class="text-center">
        <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-primary/8 text-primary">
            <x-icon name="clock" class="size-7" />
        </div>

        <h2 class="mt-4 font-display text-lg font-semibold text-on-surface">
            You're signed in, {{ str(auth()->user()->name)->before(' ')->toString() ?: auth()->user()->name }}
        </h2>

        <p class="mt-2 text-[13px] leading-5 text-on-surface-variant">
            Your account is active, but no portal has been assigned to it yet. An administrator
            will give you access — you don't need to do anything, and signing in again won't change it.
        </p>

        @php $roles = auth()->user()->roles->pluck('name'); @endphp
        @if ($roles->isNotEmpty())
            {{-- Shown so the person can quote it when they ask, and so whoever
                 they ask can see what to grant without looking it up. --}}
            <p class="mt-4 font-mono text-[10px] uppercase tracking-[0.12em] text-outline">
                {{ $roles->count() === 1 ? 'Role' : 'Roles' }}: {{ $roles->implode(', ') }}
            </p>
        @endif

        <form method="POST" action="{{ route('logout') }}" class="mt-7">
            @csrf
            <x-btn variant="secondary" class="w-full">
                <x-icon name="logout" class="size-4" /> Sign out
            </x-btn>
        </form>
    </div>
</x-guest-layout>
