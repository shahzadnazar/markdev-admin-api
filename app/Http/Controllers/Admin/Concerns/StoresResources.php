<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Support\PrivateFiles;
use App\Models\LessonResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Validating and building a resource, for whichever thing owns it.
 *
 * A resource can hang off a lesson or off a course, and the rules are the same
 * either way — file-or-link, http/https only, 5 MB. Choosing one table for
 * both levels was only worth doing if the rules also lived once; two copies of
 * an invariant is how they drift, and this one is a security rule as much as a
 * validation rule.
 *
 * The caller supplies the owner. Nothing here knows or cares which it is.
 */
trait StoresResources
{
    /** file or link — whichever the form declared, defaulting to file. */
    protected function resourceKind(Request $request): string
    {
        return $request->input('kind') === LessonResource::KIND_LINK
            ? LessonResource::KIND_LINK
            : LessonResource::KIND_FILE;
    }

    /**
     * The rules for adding a resource.
     *
     * `kind` decides which other field is required, which is the whole reason
     * it is a stored discriminator rather than an inference from whichever
     * column happens to be null.
     */
    protected function validateResource(Request $request, string $kind): void
    {
        $request->validate([
            'kind' => ['nullable', Rule::in([LessonResource::KIND_FILE, LessonResource::KIND_LINK])],
            // Attachment-style: 5 MB, no mimes list, so a zip of course
            // material is accepted.
            'file' => [Rule::requiredIf($kind === LessonResource::KIND_FILE), 'file', 'max:5120'],
            // http/https only. A resource list that can carry javascript: or
            // data: is a stored-XSS delivery mechanism dressed as a reading
            // list, and these render as anchors students click.
            //
            // link_url, not url: the lesson edit form has a `url` field for
            // the video's watch URL. Two forms can post the same name safely,
            // but old() cannot tell them apart, so a failed video save would
            // repopulate the resource link box with a YouTube watch URL.
            'link_url' => [
                Rule::requiredIf($kind === LessonResource::KIND_LINK),
                'nullable', 'url', 'max:2000', 'starts_with:http://,https://',
            ],
            'link_name' => [Rule::requiredIf($kind === LessonResource::KIND_LINK), 'nullable', 'string', 'max:255'],
        ], [
            'link_url.starts_with' => 'Links must start with http:// or https://.',
        ]);
    }

    /**
     * The attributes to write, minus the owner.
     *
     * @return array<string, mixed>
     */
    protected function resourceAttributes(Request $request, string $kind): array
    {
        if ($kind === LessonResource::KIND_LINK) {
            return [
                'name' => $request->string('link_name')->value(),
                'kind' => LessonResource::KIND_LINK,
                'url' => $request->string('link_url')->value(),
            ];
        }

        $file = $request->file('file');

        return [
            'name' => $file->getClientOriginalName(),
            'kind' => LessonResource::KIND_FILE,
            'file_path' => $file->store('resources', PrivateFiles::DISK),
            'file_type' => $file->getClientOriginalExtension() ?: $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ];
    }
}
