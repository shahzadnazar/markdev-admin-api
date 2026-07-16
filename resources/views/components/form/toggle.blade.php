@props(['label', 'name', 'checked' => false, 'hint' => null])

<label class="flex cursor-pointer items-start gap-3 rounded-xl border border-outline-variant/60 bg-white p-4 transition hover:border-primary/40">
    <input type="hidden" name="{{ $name }}" value="0">
    <input
        type="checkbox"
        name="{{ $name }}"
        value="1"
        @checked(old($name, $checked))
        {{ $attributes->merge(['class' => 'check mt-0.5']) }}
    >
    <span class="min-w-0">
        <span class="block text-[13px] font-medium text-on-surface">{{ $label }}</span>
        @if ($hint)
            <span class="mt-0.5 block text-xs text-outline">{{ $hint }}</span>
        @endif
    </span>
</label>
