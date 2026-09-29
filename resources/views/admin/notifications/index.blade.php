{{-- The full list behind the bell, for EVERY panel user. --}}
<x-admin.layout title="Notifications">
    <x-page-header eyebrow="You" title="Notifications"
        description="Everything the bell has rung for. Unread first."
        :crumbs="['Dashboard' => \App\Support\PortalHome::url(), 'Notifications' => null]">
        <x-slot:meta>
            @if ($unread > 0)
                <span class="rounded-full bg-error-container px-2.5 py-0.5 font-mono text-[11px] font-semibold text-error">{{ $unread }} unread</span>
            @endif
        </x-slot:meta>
        <x-slot:actions>
            @if ($unread > 0)
                <form method="POST" action="{{ route('admin.notifications.read-all') }}">
                    @csrf
                    <x-btn variant="secondary" size="sm" type="submit">
                        <x-icon name="check" class="size-4" /> Mark all read
                    </x-btn>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-card class="p-0">
        @forelse ($notifications as $notification)
            @php
                // The same precedence the topbar dropdown uses: a link meant for
                // this panel, else an /admin path, else nowhere. The academy's
                // six classes were written for the student portal and carry
                // portal paths, and a portal path followed from here would 404.
                $adminUrl = $notification->data['admin_action_url'] ?? null;
                $actionUrl = $notification->data['action_url'] ?? null;
                $hasTarget = (is_string($adminUrl) && $adminUrl !== '')
                    || (is_string($actionUrl) && str_starts_with($actionUrl, '/admin'));
            @endphp
            <div class="flex items-start gap-3 border-b border-surface-ice/70 px-5 py-4 last:border-0 {{ $notification->read_at === null ? 'bg-primary/[0.03]' : '' }}">
                {{-- Unread is a dot and a tint, not bold text: a page of bold
                     reads as shouting and the tint carries it on its own. --}}
                <span class="mt-1.5 size-2 shrink-0 rounded-full {{ $notification->read_at === null ? 'bg-primary' : 'bg-transparent' }}"></span>

                <div class="min-w-0 flex-1">
                    <p class="text-[13px] font-semibold text-on-surface">{{ $notification->data['title'] ?? 'Notification' }}</p>
                    <p class="mt-0.5 text-[13px] leading-5 text-on-surface-variant">{{ $notification->data['message'] ?? '' }}</p>
                    <p class="mt-1 font-mono text-[10px] text-outline">
                        {{ $notification->created_at->diffForHumans() }}
                        @if ($notification->read_at !== null)
                            · read
                        @endif
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-1.5">
                    @if ($hasTarget)
                        {{-- A POST, because opening it marks it read. One action:
                             a notification you have opened IS read, and asking
                             somebody to tick it afterwards is how a list stays
                             permanently full. --}}
                        <form method="POST" action="{{ route('admin.notifications.read', $notification->id) }}">
                            @csrf
                            <x-btn variant="ghost" size="sm" type="submit">
                                Open <x-icon name="chevron-right" class="size-3.5" />
                            </x-btn>
                        </form>
                    @elseif ($notification->read_at === null)
                        <form method="POST" action="{{ route('admin.notifications.read', $notification->id) }}">
                            @csrf
                            <x-btn variant="ghost" size="sm" type="submit">Mark read</x-btn>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <x-empty-state icon="inbox" title="You're all caught up"
                description="Notifications about your work — mentions, assignments, leave decisions and deadlines — appear here." />
        @endforelse

        @if ($notifications->hasPages())
            <div class="border-t border-surface-ice px-5 py-3" data-pagination>{{ $notifications->links() }}</div>
        @endif
    </x-card>
</x-admin.layout>
