@props(['label', 'message', 'href' => null, 'action' => null])

{{-- A REQUIRED DROPDOWN THAT HAS NOTHING TO OFFER, saying so.

     Drawn in place of the <select>, not beside it. An empty required control is
     a dead end: the field cannot be filled, the form cannot be submitted, and
     the screen gives no clue what is missing or where to go. It is the same
     fault as a breadcrumb that answers 403 — something offered that refuses
     you — and it is fixed the same way, by naming the next step.

     The link is OPTIONAL because not every viewer may take it. A team lead can
     create a task but not a team, so they are told what is missing without
     being handed a link that would 403 at them. The caller decides with @can. --}}
<div class="min-w-0">
    <x-form.label :value="$label" required />

    <div class="rounded-xl border border-dashed border-outline-variant bg-surface-ice/50 px-4 py-3">
        <p class="text-[13px] leading-5 text-on-surface-variant">{{ $message }}</p>

        @if ($href)
            <a href="{{ $href }}" class="mt-2 inline-flex items-center gap-1 text-[13px] font-semibold text-primary hover:underline">
                {{ $action ?? 'Set one up' }}
                <x-icon name="chevron-right" class="size-3.5" />
            </a>
        @endif
    </div>
</div>
