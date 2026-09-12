<?php

namespace App\Http\Controllers\Admin;

use App\Support\PrivateFiles;
use App\Http\Controllers\Admin\Concerns\RestrictsToInstructor;
use App\Http\Controllers\Admin\Concerns\FiltersByValues;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Note;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class NoteController extends Controller
{
    use FiltersByValues, RestrictsToInstructor;

    public function index(Request $request): View
    {
        $mine = $this->managedCourseIds($request);

        // Built before the list, because the options are also the values the
        // filter will accept — and this list is already narrowed to an
        // instructor's own courses.
        $courses = Course::query()
            ->when($mine !== null, fn ($query) => $query->whereIn('id', $mine))
            ->orderBy('title')
            ->get(['id', 'title']);
        $courseIds = $this->filterIds($request, 'course', $courses->pluck('id')->all());

        $notes = Note::query()
            ->with(['course', 'instructor'])
            ->when($mine !== null, fn ($query) => $query->whereIn('course_id', $mine))
            ->when($courseIds, fn ($query) => $query->whereIn('course_id', $courseIds))
            ->when(
                $request->filled('search'),
                fn ($query) => $query->where('title', 'like', '%' . trim($request->string('search')) . '%')
            )
            ->latest()
            ->paginate(10)
            ->appends(\Illuminate\Support\Arr::except($request->query(), ['partial', 'page']));

        // Live search re-renders only the results, so typing never reloads the
        // page and the cursor stays in the search box. The Filter button still
        // submits the form normally and lands here without `partial`.
        if ($request->boolean('partial')) {
            return view('admin.notes._results', ['notes' => $notes]);
        }

        return view('admin.notes.index', [
            'notes' => $notes,
            'courses' => $courses,
            'selected' => ['course' => $courseIds],
        ]);
    }

    public function create(Request $request): View
    {
        $mine = $this->managedCourseIds($request);

        $courses = Course::query()
            ->when(
                $mine !== null,
                fn ($query) => $query->whereIn('id', $mine)
            )
            ->orderBy('title')
            ->get(['id', 'title']);

        return view('admin.notes.form', [
            'note' => null,
            'courses' => $courses,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $this->authorizeCourseAccess($request, $data['course_id']);

        $file = $request->file('file');
        $data['file_path'] = $file->store('notes', PrivateFiles::DISK);
        $data['file_type'] = $file->getClientMimeType();
        $data['size_bytes'] = $file->getSize();
        $data['instructor_id'] = $request->user()->id;

        unset($data['file']);

        Note::create($data);

        return redirect()
            ->route('admin.notes.index')
            ->with('success', 'Note uploaded successfully.');
    }

    public function edit(Request $request, Note $note): View
    {
        $this->authorizeCourseAccess($request, $note->course_id);

        $mine = $this->managedCourseIds($request);

        $courses = Course::query()
            ->when(
                $mine !== null,
                fn ($query) => $query->whereIn('id', $mine)
            )
            ->orderBy('title')
            ->get(['id', 'title']);

        return view('admin.notes.form', [
            'note' => $note,
            'courses' => $courses,
        ]);
    }

    public function update(Request $request, Note $note): RedirectResponse
    {
        $this->authorizeCourseAccess($request, $note->course_id);

        $data = $this->validated($request, false);

        if ($request->hasFile('file')) {
            if ($note->file_path) {
                PrivateFiles::forget($note->file_path);
            }

            $file = $request->file('file');

            $data['file_path'] = $file->store('notes', PrivateFiles::DISK);
            $data['file_type'] = $file->getClientMimeType();
            $data['size_bytes'] = $file->getSize();
        }

        unset($data['file']);

        $note->update($data);

        return redirect()
            ->route('admin.notes.index')
            ->with('success', 'Note updated successfully.');
    }

    public function destroy(Request $request, Note $note): RedirectResponse
    {
        $this->authorizeCourseAccess($request, $note->course_id);

        $note->delete();

        return redirect()
            ->route('admin.notes.index')
            ->with('success', 'Note moved to trash.');
    }

    public function download(Request $request, Note $note)
    {
        $this->authorizeCourseAccess($request, $note->course_id);

        abort_unless(
            $note->file_path && Storage::disk('public')->exists($note->file_path),
            404
        );

        return Storage::disk('public')->download(
            $note->file_path,
            $note->title . '.' . $this->extension($note->file_type)
        );
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, bool $fileRequired = true): array
    {
        return $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // 5 MB, and the document list is deliberately unchanged. A note
            // is one readable document, not a bundle: extension() maps each of
            // these mimes to a filename for the download route, and an archive
            // has nothing to map to. Archives belong on lesson resources.
            'file' => [
                $fileRequired ? 'required' : 'nullable',
                'file',
                'max:5120',
                'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt',
            ],
        ]);
    }

    protected function extension(?string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/plain' => 'txt',
            default => 'file',
        };
    }
}