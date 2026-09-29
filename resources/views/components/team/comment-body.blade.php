@props(['comment'])

@php
    // Escaped FIRST, then the mentions wrapped. The other order would let a
    // comment body inject markup through its own text, and a chat box is
    // exactly where somebody would try it.
    $html = e($comment->body);

    // Highlighted from the STORED mention rows, never by re-parsing the text:
    // the rows are what the notification phase will read, so the screen and
    // that phase agree about who was mentioned. A handle that matched nobody
    // on this surface has no row and stays plain text, which is the whole
    // point — people type @ for other reasons.
    foreach ($comment->mentions as $mention) {
        $handle = $mention->user?->mentionHandle();

        if ($handle === null || $handle === '') {
            continue;
        }

        $html = preg_replace(
            '/@'.preg_quote($handle, '/').'\b/i',
            '<span class="rounded bg-primary/10 px-1 font-medium text-primary">@'.$handle.'</span>',
            $html,
        );
    }
@endphp

{{-- Nothing is delivered from a mention yet; notifications are the next
     phase. This is the highlight and nothing more. --}}
<p {{ $attributes->merge(['class' => 'whitespace-pre-line text-[13px] leading-5 text-on-surface']) }}>{!! $html !!}</p>
