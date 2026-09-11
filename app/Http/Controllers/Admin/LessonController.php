<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RestrictsToInstructor;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonResource;
use App\Models\Module;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LessonController extends Controller
{
    use RestrictsToInstructor;

    public function store(Request $request, Module $module): RedirectResponse
    {
        $this->authorizeCourseAccess($request, $module->course_id);
        $data = $this->validated($request);

        $lesson = $module->lessons()->create([
            ...collect($data)->except(['provider', 'url', 'embed_url', 'thumbnail'])->all(),
            'course_id' => $module->course_id,
            'position' => ((int) $module->lessons()->max('position')) + 1,
        ]);

        $this->syncVideo($lesson, $data, $request);

        return redirect()
            ->route('admin.courses.show', $module->course_id)
            ->with('success', "Lesson \"{$lesson->title}\" added.");
    }

    public function edit(Lesson $lesson): View
    {
        $this->authorizeCourseAccess(request(), $lesson->course_id);
        return view('admin.courses.lesson', [
            'lesson' => $lesson->load(['module', 'course', 'video', 'resources']),
        ]);
    }

    public function update(Request $request, Lesson $lesson): RedirectResponse
    {
        $this->authorizeCourseAccess($request, $lesson->course_id);
        $data = $this->validated($request);

        $lesson->update(collect($data)->except(['provider', 'url', 'embed_url', 'thumbnail'])->all());
        $this->syncVideo($lesson, $data, $request);

        return redirect()
            ->route('admin.lessons.edit', $lesson)
            ->with('success', 'Lesson saved.');
    }

    /** Reorder within the module via up/down buttons. */
    public function move(Request $request, Lesson $lesson): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);

        $neighbour = Lesson::where('module_id', $lesson->module_id)
            ->when($data['direction'] === 'up',
                fn ($query) => $query->where('position', '<', $lesson->position)->orderByDesc('position'),
                fn ($query) => $query->where('position', '>', $lesson->position)->orderBy('position'))
            ->first();

        if ($neighbour) {
            [$lesson->position, $neighbour->position] = [$neighbour->position, $lesson->position];
            $lesson->save();
            $neighbour->save();
        }

        return back();
    }

    public function destroy(Lesson $lesson): RedirectResponse
    {
        $this->authorizeCourseAccess(request(), $lesson->course_id);
        $courseId = $lesson->course_id;
        $title = $lesson->title;
        $lesson->delete();

        return redirect()
            ->route('admin.courses.show', $courseId)
            ->with('success', "Lesson \"{$title}\" deleted.");
    }

    /**
     * Add a resource: a file OR a link, never both.
     *
     * The kind decides which other fields are required, which is the whole
     * reason it is a stored discriminator rather than an inference from which
     * column happens to be null.
     */
    public function storeResource(Request $request, Lesson $lesson): RedirectResponse
    {
        $kind = $request->input('kind') === LessonResource::KIND_LINK
            ? LessonResource::KIND_LINK
            : LessonResource::KIND_FILE;

        $request->validate([
            'kind' => ['nullable', Rule::in([LessonResource::KIND_FILE, LessonResource::KIND_LINK])],
            // Attachment-style: 5 MB, no mimes list, so a zip of course
            // material is accepted — as it already was.
            'file' => [Rule::requiredIf($kind === LessonResource::KIND_FILE), 'file', 'max:5120'],
            // http/https only. A resource list that can carry javascript: or
            // data: is a stored-XSS delivery mechanism dressed as a reading
            // list, and the link is rendered as an anchor students click.
            // link_url, not url: the lesson edit form on the same page already
            // has a `url` field for the video's watch URL. Two forms can post
            // the same name safely, but old() cannot tell them apart, so a
            // failed video save would repopulate the resource link box with a
            // YouTube watch URL and vice versa.
            'link_url' => [
                Rule::requiredIf($kind === LessonResource::KIND_LINK),
                'nullable', 'url', 'max:2000', 'starts_with:http://,https://',
            ],
            'link_name' => [Rule::requiredIf($kind === LessonResource::KIND_LINK), 'nullable', 'string', 'max:255'],
        ], [
            'link_url.starts_with' => 'Links must start with http:// or https://.',
        ]);

        if ($kind === LessonResource::KIND_LINK) {
            $lesson->resources()->create([
                'name' => $request->string('link_name')->value(),
                'kind' => LessonResource::KIND_LINK,
                'url' => $request->string('link_url')->value(),
            ]);

            return back()->with('success', 'Link added.');
        }

        $file = $request->file('file');
        $path = $file->store('resources', 'public');

        $lesson->resources()->create([
            'name' => $file->getClientOriginalName(),
            'kind' => LessonResource::KIND_FILE,
            'file_path' => $path,
            'file_type' => $file->getClientOriginalExtension() ?: $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        return back()->with('success', 'Resource uploaded.');
    }

    public function destroyResource(Lesson $lesson, LessonResource $resource): RedirectResponse
    {
        abort_unless($resource->lesson_id === $lesson->id, 404);

        if ($resource->file_path) {
            Storage::disk('public')->delete($resource->file_path);
        }
        $resource->delete();

        return back()->with('success', 'Resource removed.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['video', 'article', 'quiz', 'assignment', 'resource'])],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:6000'],
            'is_preview' => ['nullable', 'boolean'],
            'content' => ['nullable', 'string'],
            'provider' => ['nullable', Rule::in(['youtube', 'vimeo', 'self_hosted'])],
            'url' => ['nullable', 'string', 'max:1000'],
            'embed_url' => ['nullable', 'string', 'max:1000'],
            // An image field: 1 MB. `image` already refuses an archive.
            'thumbnail' => ['nullable', 'image', 'max:1024'],
        ]);

        $data['is_preview'] = $request->boolean('is_preview');
        if ($data['type'] !== 'article') {
            $data['content'] = null;
        }

        return $data;
    }

    /** Keep the lesson's video row in sync for video lessons. */
    protected function syncVideo(Lesson $lesson, array $data, Request $request): void
    {
        if ($data['type'] !== 'video') {
            $lesson->video()->delete();

            return;
        }

        $video = $lesson->video;

        if ($video === null && empty($data['url']) && empty($data['embed_url'])) {
            return;
        }

        $attributes = [
            'provider' => $data['provider'] ?? 'youtube',
            'url' => $data['url'] ?? '',
            'embed_url' => $data['embed_url'] ?? null,
        ];

        // The video's real duration is only seeded on creation — later lesson
        // edits must not clobber it with the display duration.
        if ($video === null) {
            $attributes['duration_seconds'] = ($data['duration_minutes'] ?? 0) * 60;
        }

        $video = $lesson->video()->updateOrCreate([], $attributes);

        if ($file = $request->file('thumbnail')) {
            if ($video->thumbnail_path) {
                Storage::disk('public')->delete($video->thumbnail_path);
            }
            $video->update(['thumbnail_path' => $file->store('courses', 'public')]);
        }
    }
}
