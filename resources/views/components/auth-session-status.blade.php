@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'rounded-lg bg-success-container px-4 py-3 text-sm font-medium text-success']) }}>
        {{ $status }}
    </div>
@endif
