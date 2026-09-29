@props([
    'threads',
    'storeRoute',
    'updateRoute',   // a closure: fn ($comment) => url
    'destroyRoute',  // a closure: fn ($comment) => url
    'placeholder' => 'Write a message…',
    'mayAnnounce' => false,
    'emptyTitle' => 'Nothing here yet',
])

{{-- ONE renderer for all three surfaces. The threading rule is one level, so
     this is two loops and never a recursive include — which is also why the
     rule exists: two levels is a screen anyone can read.

     Who may edit or delete is decided on the server; these controls only
     avoid offering what would be refused. A team-lead sees no control on
     somebody else's message, because a lead is not a moderator. --}}

@php $viewer = auth()->user(); @endphp

<div class="space-y-4">
    <x-card>
        <form method="POST" action="{{ $storeRoute }}" class="space-y-3">
            @csrf
            <x-form.textarea label="" name="body" rows="3" :placeholder="$placeholder" required />
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-[11px] text-outline">
                    Mention somebody with <span class="font-mono">@name.surname</span>. A name nobody here answers to stays plain text.
                </p>
                <div class="flex items-center gap-2.5">
                    @if ($mayAnnounce)
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-outline-variant/60 px-3 py-1.5">
                            <input type="checkbox" name="is_announcement" value="1" class="check">
                            <span class="text-[12px] font-medium text-on-surface">Announcement</span>
                        </label>
                    @endif
                    <x-btn size="sm"><x-icon name="check" class="size-4" /> Post</x-btn>
                </div>
            </div>
        </form>
    </x-card>

    @forelse ($threads as $thread)
        <x-card @class(['border-l-4 border-l-primary' => (bool) ($thread->is_announcement ?? false)])>
            @if ($thread->is_announcement ?? false)
                <p class="mb-2 font-mono text-[10px] font-semibold uppercase tracking-[0.1em] text-primary">Announcement</p>
            @endif

            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-on-surface">{{ $thread->author?->name }}</p>
                    <p class="mt-0.5 font-mono text-[11px] text-outline">{{ $thread->created_at?->format('j M Y · g:i A') }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                    @if ($viewer && $viewer->getKey() === $thread->user_id)
                        <form method="POST" action="{{ $destroyRoute($thread) }}">
                            @csrf @method('DELETE')
                            <button type="submit" title="Delete" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                <x-icon name="trash" class="size-4" />
                            </button>
                        </form>
                    @elseif ($viewer && $viewer->hasRole('super-admin'))
                        <form method="POST" action="{{ $destroyRoute($thread) }}">
                            @csrf @method('DELETE')
                            <button type="submit" title="Delete as super admin" class="rounded-lg p-2 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                <x-icon name="trash" class="size-4" />
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <x-team.comment-body :comment="$thread" class="mt-2" />

            @if ($thread->replies->isNotEmpty())
                <div class="mt-3 space-y-3 border-l-2 border-surface-ice pl-4">
                    @foreach ($thread->replies as $reply)
                        <div>
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-[13px] font-medium text-on-surface">
                                    {{ $reply->author?->name }}
                                    <span class="ml-1 font-mono text-[10px] font-normal text-outline">{{ $reply->created_at?->format('j M · g:i A') }}</span>
                                </p>
                                @if ($viewer && ($viewer->getKey() === $reply->user_id || $viewer->hasRole('super-admin')))
                                    <form method="POST" action="{{ $destroyRoute($reply) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" title="Delete" class="rounded-lg p-1.5 text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                                            <x-icon name="trash" class="size-3.5" />
                                        </button>
                                    </form>
                                @endif
                            </div>
                            <x-team.comment-body :comment="$reply" class="mt-1" />
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- One level: a reply form appears under an opener and never under
                 a reply. --}}
            <form method="POST" action="{{ $storeRoute }}" class="mt-3 flex items-end gap-2 border-t border-surface-ice pt-3">
                @csrf
                <input type="hidden" name="parent_id" value="{{ $thread->id }}">
                <div class="min-w-0 flex-1">
                    <x-form.textarea label="" name="body" rows="1" placeholder="Reply…" required />
                </div>
                <x-btn size="sm" variant="secondary">Reply</x-btn>
            </form>
        </x-card>
    @empty
        <x-card><x-empty-state icon="inbox" :title="$emptyTitle" /></x-card>
    @endforelse
</div>
