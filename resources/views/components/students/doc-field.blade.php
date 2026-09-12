@props(['name', 'label', 'accept', 'maxKb', 'required' => false, 'existing' => null, 'kind' => 'any', 'profile' => null, 'doc' => null])

@php
    /*
     * `existing` is still the stored PATH — it says whether a document is
     * there, and whether it is an image. It is no longer where the browser
     * fetches it from: that is a route now, because these are identity
     * documents and the public disk served them to anyone with the path.
     */
    $existingUrl = ($existing && $profile && $doc) ? $profile->documentSrc($doc) : null;
    $existingIsImage = \App\Models\StudentProfile::isImagePath($existing);
@endphp

{{--
    A student document field: the shared drop zone with this screen's wording.

    It used to be a second implementation of the same idea — its own dashed
    button, its own preview, its own size check, and "max 1 MB" typed out in
    four places. It is a wrapper now, so a fix to dragging, to the focus ring
    or to how the size chip is worked out lands here too.

    The three fields no longer share one limit, so `max-kb` is passed in.
    StudentController::validated() gives `photo` 1 MB — it takes images only —
    and the two documents 5 MB, because they also take a PDF and are usually a
    phone photo of a card. Mimes are jpg,jpeg,png,webp (+pdf for the
    documents); none of them takes an archive. Required only while creating.
--}}
<x-form.dropzone
    :name="$name"
    :label="$label"
    :accept="$accept"
    :accept-label="$kind === 'image' ? 'JPG, PNG, WEBP' : 'JPG, PNG, WEBP, PDF'"
    :max-kb="$maxKb"
    :required="$required"
    preview
    :existing="$existingUrl"
    :existing-is-image="$existingIsImage"
    existing-label="Current file on record"
/>
