<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RestrictsToInstructor;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourseController extends Controller
{
    use RestrictsToInstructor;

    public function index(Request $request): View
    {
        $courses = Course::query()
            ->when(($mine = $this->managedCourseIds($request)) !== null, fn ($query) => $query->whereIn('id', $mine))
            ->with(['category', 'instructor'])
            ->withCount(['lessons', 'enrollments'])
            ->when($request->filled('search'), fn ($query) => $query->where('title', 'like', '%'.trim($request->string('search')).'%'))
            ->when($request->filled('category'), fn ($query) => $query->where('category_id', $request->integer('category')))
            ->when($request->filled('level'), fn ($query) => $query->where('level', $request->string('level')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->string('trashed')->toString() === '1', fn ($query) => $query->onlyTrashed())
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('admin.courses.index', [
            'courses' => $courses,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): View
    {
        return view('admin.courses.form', [
            'course' => null,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'instructors' => $this->instructors(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail_path'] = $request->file('thumbnail')->store('courses', 'public');
        }

        if ($this->managedCourseIds($request) !== null) {
            $data['instructor_id'] = $request->user()->id;
        }

        $course = Course::create($data);

        return redirect()->route('admin.courses.show', $course)->with('success', "Course \"{$course->title}\" created — now build the curriculum.");
    }

    /** The course builder. */
    public function show(Course $course): View
    {
        $this->authorizeCourseAccess(request(), $course->id);
        $course->load([
            'category',
            'instructor',
            'modules.lessons.video',
            'modules.lessons.resources',
        ])->loadCount('enrollments');

        return view('admin.courses.show', ['course' => $course]);
    }

    public function edit(Course $course): View
    {
        $this->authorizeCourseAccess(request(), $course->id);
        return view('admin.courses.form', [
            'course' => $course,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'instructors' => $this->instructors(),
        ]);
    }

    public function update(Request $request, Course $course): RedirectResponse
    {
        $this->authorizeCourseAccess($request, $course->id);
        $data = $this->validated($request, $course);

        if ($request->hasFile('thumbnail')) {
            if ($course->thumbnail_path) {
                Storage::disk('public')->delete($course->thumbnail_path);
            }
            $data['thumbnail_path'] = $request->file('thumbnail')->store('courses', 'public');
        }

        $course->update($data);

        return redirect()->route('admin.courses.show', $course)->with('success', "Course \"{$course->title}\" updated.");
    }

    public function togglePublish(Course $course): RedirectResponse
    {
        if ($course->status === 'published') {
            $course->update(['status' => 'draft']);

            return back()->with('success', "\"{$course->title}\" unpublished.");
        }

        $course->update(['status' => 'published', 'published_at' => $course->published_at ?? now()]);

        return back()->with('success', "\"{$course->title}\" is now live.");
    }

    public function destroy(Course $course): RedirectResponse
    {
        $this->authorizeCourseAccess(request(), $course->id);
        $title = $course->title;
        $course->delete();

        return redirect()->route('admin.courses.index')->with('success', "Course \"{$title}\" moved to trash.");
    }

    public function restore(Course $course): RedirectResponse
    {
        $course->restore();

        return redirect()->route('admin.courses.index', ['trashed' => 1])->with('success', "Course \"{$course->title}\" restored.");
    }

    public function forceDestroy(Course $course): RedirectResponse
    {
        if ($course->thumbnail_path) {
            Storage::disk('public')->delete($course->thumbnail_path);
        }
        $course->forceDelete();

        return redirect()->route('admin.courses.index', ['trashed' => 1])->with('success', 'Course permanently deleted.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Course $course = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('courses', 'slug')->ignore($course?->id)],
            'category_id' => ['required', Rule::exists('categories', 'id')],
            'instructor_id' => ['required', Rule::exists('users', 'id')],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'duration_label' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'level' => ['required', Rule::in(['beginner', 'intermediate', 'advanced'])],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'is_free' => ['nullable', 'boolean'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'tags' => ['nullable', 'string', 'max:500'],
            'thumbnail' => ['nullable', 'image', 'max:4096'],
        ]);

        // Blank slug: build one from the title, keeping it unique.
        if (empty($data['slug'])) {
            $base = Str::slug($data['title']) ?: 'course';
            $slug = $base;
            $suffix = 2;
            while (Course::withTrashed()->where('slug', $slug)
                ->when($course, fn ($query) => $query->where('id', '!=', $course->id))
                ->exists()) {
                $slug = $base.'-'.$suffix++;
            }
            $data['slug'] = $slug;
        }

        $data['is_free'] = $request->boolean('is_free');
        $data['price'] = $data['is_free'] ? null : ($data['price'] ?? null);
        $data['tags'] = collect(explode(',', (string) ($data['tags'] ?? '')))
            ->map(fn (string $tag) => trim($tag))
            ->filter()
            ->values()
            ->all();

        if ($data['status'] === 'published' && ! $course?->published_at) {
            $data['published_at'] = now();
        }

        unset($data['thumbnail']);

        return $data;
    }

    protected function instructors()
    {
        return User::role('instructor')->orderBy('name')->get(['id', 'name']);
    }
}
