@props(['name'])

@error($name)
    <p {{ $attributes->merge(['class' => 'mt-1.5 text-xs font-medium text-error']) }}>{{ $message }}</p>
@enderror
