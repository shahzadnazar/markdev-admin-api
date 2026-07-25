@props(['for' => null, 'value' => null, 'required' => false])

<label @if($for) for="{{ $for }}" @endif {{ $attributes->merge(['class' => 'mb-1.5 block text-[13px] font-medium text-on-surface']) }}>
    {{ $value ?? $slot }}@if ($required)<span class="ml-0.5 text-error" aria-hidden="true">*</span><span class="sr-only"> (required)</span>@endif
</label>
