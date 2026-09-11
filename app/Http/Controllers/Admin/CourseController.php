<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\FiltersByValues;
use App\Http\Controllers\Admin\Concerns\FiltersTrashed;
use App\Http\Controllers\Admin\Concerns\RestrictsToInstructor;
use App\Http\Controllers\Admin\Concerns\StoresResources;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Course;
use App\Models\LessonResource;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourseController extends Controller
{
    use FiltersByValues, FiltersTrashed, RestrictsToInstructor, StoresResources;

    /** What the level and status filters may be asked for. */
    public const LEVELS = ['beginner', 'intermediate', 'advanced'];

    public const STATUSES = ['draft', 'published', 'archived'];

    /**
     * Categories worth offering as a filter, for this viewer.
     *
     * An instructor's list was every category in the academy, which named
     * fields they do not teach and cannot see a single course in. Narrowed to
     * the categories their own courses actually sit in — the same set the list
     * below is already limited to.
     *
     * @param  array<int, int>|null  $managedCourseIds  null when unrestricted
     */
    protected function filterableCategories(?array $managedCourseIds)
    {
        return Category::query()
            ->when($managedCourseIds !== null, fn ($query) => $query->whereHas(
                'courses',
                fn ($courses) => $courses->whereIn('id', $managedCourseIds),
            ))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function index(Request $request): View
    {
        // Only for someone who could act on what it shows. Instructors and
        // managers hold courses.view but neither delete nor restore, so the
        // trashed list was a room with nothing in it they could touch — and a
        // stray ?trashed=1 in the URL is ignored rather than refused.
        $mayViewTrash = $this->mayViewTrash($request, 'courses.delete', 'courses.restore');
        $trashed = $this->showingTrashed($request, 'courses.delete', 'courses.restore');

        $mine = $this->managedCourseIds($request);
        $categories = $this->filterableCategories($mine);

        // The options a filter offers are also the values it will accept, so an
        // instructor's own list is what bounds them: ?category[]=99 for a field
        // they do not teach drops out rather than widening their view.
        $categoryIds = $this->filterIds($request, 'category', $categories->pluck('id')->all());
        $levels = $this->filterValues($request, 'level', self::LEVELS);
        $statuses = $this->filterValues($request, 'status', self::STATUSES);

        $courses = Course::query()
            ->when($mine !== null, fn ($query) => $query->whereIn('id', $mine))
            ->with(['category', 'instructor'])
            ->withCount(['lessons', 'enrollments'])
            ->when($request->filled('search'), fn ($query) => $query->where('title', 'like', '%'.trim($request->string('search')).'%'))
            // `when($array)` and not `when(filled(...))`: an empty selection is
            // no filter at all, where whereIn('...', []) would match no rows and
            // read as "there is nothing here".
            ->when($categoryIds, fn ($query) => $query->whereIn('category_id', $categoryIds))
            ->when($levels, fn ($query) => $query->whereIn('level', $levels))
            ->when($statuses, fn ($query) => $query->whereIn('status', $statuses))
            ->when($trashed, fn ($query) => $query->onlyTrashed())
            ->latest()
            ->paginate(10)
            ->appends(\Illuminate\Support\Arr::except($request->query(), ['partial', 'page']));

        // Live search re-renders only the results, so typing never reloads the
        // page and the cursor stays in the search box. The Filter button still
        // submits the form normally and lands here without `partial`.
        if ($request->boolean('partial')) {
            return view('admin.courses._results', ['courses' => $courses]);
        }

        return view('admin.courses.index', [
            'courses' => $courses,
            'mayViewTrash' => $mayViewTrash,
            'trashed' => $trashed,
            'categories' => $categories,
            'selected' => [
                'category' => $categoryIds,
                'level' => $levels,
                'status' => $statuses,
            ],
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
    /**
     * Add a resource to the COURSE itself, not to one of its lessons.
     *
     * Same rules, same trait as the lesson-level form — one set of rules for
     * one table. The only difference is which thing owns the row.
     */
    public function storeResource(Request $request, Course $course): RedirectResponse
    {
        // Category scoping (77b6fd1): an instructor may only touch their own.
        $this->authorizeCourseAccess($request, $course->id);

        $kind = $this->resourceKind($request);
        $this->validateResource($request, $kind);

        $course->resources()->create($this->resourceAttributes($request, $kind));

        return back()->with('success', $kind === LessonResource::KIND_LINK ? 'Link added.' : 'Resource uploaded.');
    }

    public function destroyResource(Request $request, Course $course, LessonResource $resource): RedirectResponse
    {
        $this->authorizeCourseAccess($request, $course->id);
        // Belt and braces: the route model binding does not check the pairing,
        // so a resource id from another course would otherwise be deletable by
        // anyone who can edit any course.
        abort_unless($resource->course_id === $course->id, 404);

        if ($resource->file_path) {
            Storage::disk('public')->delete($resource->file_path);
        }
        $resource->delete();

        return back()->with('success', 'Resource removed.');
    }

    public function show(Course $course): View
    {
        $this->authorizeCourseAccess(request(), $course->id);
        $course->load([
            'category',
            'instructor',
            'modules.lessons.video',
            'modules.lessons.resources',
            // Course-level resources: the relation excludes lesson-level rows,
            // so the two lists cannot bleed into each other.
            'resources',
        ])->loadCount('enrollments');

        $lessonIds = $course->modules->flatMap->lessons->pluck('id');

        $watches = \App\Models\LessonVideoProgress::available()
            ? \App\Models\LessonVideoProgress::query()
                ->whereIn('lesson_id', $lessonIds)
                ->with('user:id,name,email')
                ->get()
                ->groupBy('lesson_id')
            : collect();

        $enrolledCount = $course->enrollments_count;
        $required = \App\Models\LessonVideoProgress::requiredPercent();

        // Every lesson gets an entry, not just the watched ones — otherwise a
        // lesson nobody has opened yet loses its denominator and reads "0/0"
        // on a course that has students enrolled.
        $watchStats = $lessonIds->mapWithKeys(function ($lessonId) use ($watches, $required, $enrolledCount) {
            $rows = $watches->get($lessonId, collect());

            return [$lessonId => [
                'rows' => $rows->sortByDesc('coverage_percent')->values(),
                'full' => $rows->where('coverage_percent', '>=', $required)->count(),
                'started' => $rows->where('watched_seconds', '>', 0)->count(),
                'enrolled' => $enrolledCount,
            ]];
        });

        return view('admin.courses.show', [
            'course' => $course,
            'watchStats' => $watchStats,
            'requiredPercent' => $required,
        ]);
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
            // An image field: 1 MB. `image` already refuses an archive.
            'thumbnail' => ['nullable', 'image', 'max:1024'],
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
