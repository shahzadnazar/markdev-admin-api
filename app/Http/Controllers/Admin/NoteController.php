<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RestrictsToInstructor;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Note;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class NoteController extends Controller
{
    use RestrictsToInstructor;

    public function index(Request $request): View
    {
        $notes = Note::query()
            ->with(['course', 'instructor'])
            ->when(
                ($mine = $this->managedCourseIds($request)) !== null,
                fn ($query) => $query->whereIn('course_id', $mine)
            )
            ->when(
                $request->filled('course'),
                fn ($query) => $query->where('course_id', $request->integer('course'))
            )
            ->when(
                $request->filled('search'),
                fn ($query) => $query->where('title', 'like', '%' . trim($request->string('search')) . '%')
            )
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $courses = Course::query()
            ->when(
                $mine !== null,
                fn ($query) => $query->whereIn('id', $mine)
            )
            ->orderBy('title')
            ->get(['id', 'title']);

        return view('admin.notes.index', [
            'notes' => $notes,
            'courses' => $courses,
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
        $data['file_path'] = $file->store('notes', 'public');
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
                Storage::disk('public')->delete($note->file_path);
            }

            $file = $request->file('file');

            $data['file_path'] = $file->store('notes', 'public');
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
            'file' => [
                $fileRequired ? 'required' : 'nullable',
                'file',
                'max:20480',
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