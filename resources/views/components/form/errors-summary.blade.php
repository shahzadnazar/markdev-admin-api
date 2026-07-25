{{-- Page-level validation summary: what failed and where, above the form. --}}
@if ($errors->any())
    <div role="alert" {{ $attributes->merge(['class' => 'mb-5 rounded-xl border border-error/30 bg-error-container/40 px-4 py-3']) }}>
        <p class="text-sm font-semibold text-on-surface">
            @if ($errors->count() === 1)
                One field needs attention before this can be saved
            @else
                {{ $errors->count() }} fields need attention before this can be saved
            @endif
        </p>
        <ul class="mt-1.5 list-disc space-y-0.5 pl-5 text-[13px] leading-5 text-on-surface-variant">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
