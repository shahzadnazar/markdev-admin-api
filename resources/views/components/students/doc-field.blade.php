@props(['name', 'label', 'accept', 'required' => false, 'existing' => null, 'kind' => 'any'])

@php
    $existingUrl = $existing ? \App\Models\StudentProfile::documentSrc($existing) : null;
    $existingIsImage = \App\Models\StudentProfile::isImagePath($existing);
@endphp

{{--
    A student document field: the shared drop zone with this screen's wording.

    It used to be a second implementation of the same idea — its own dashed
    button, its own preview, its own size check, and "max 1 MB" typed out in
    four places. It is a wrapper now, so a fix to dragging, to the focus ring
    or to how the size chip is worked out lands here too.

    StudentController::validated() is `max:1024` on all three, with mimes
    jpg,jpeg,png,webp (+pdf for the two documents), and required only while
    creating. `max-kb` is that rule; the chip is the rule capped by php.ini.
--}}
<x-form.dropzone
    :name="$name"
    :label="$label"
    :accept="$accept"
    :accept-label="$kind === 'image' ? 'JPG, PNG, WEBP' : 'JPG, PNG, WEBP, PDF'"
    :max-kb="1024"
    :required="$required"
    preview
    :existing="$existingUrl"
    :existing-is-image="$existingIsImage"
    existing-label="Current file on record"
/>
