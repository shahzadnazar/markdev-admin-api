<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\CourseResource;
use App\Http\Resources\EnrollmentResource;
use App\Models\Bookmark;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class CourseController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = Course::query()
            ->published()
            ->with(['category', 'instructor'])
            ->with(['enrollments' => fn ($q) => $q->where('user_id', $user->id)])
            ->withCount(['modules', 'lessons', 'enrollments as students_count']);

        if ($category = $request->query('category')) {
            $query->whereHas('category', fn (Builder $q) => $q->where('slug', $category));
        }

        if ($level = $request->query('level')) {
            $query->where('level', $level);
        }

        if ($request->filled('enrolled')) {
            $request->boolean('enrolled')
                ? $query->whereHas('enrollments', fn (Builder $q) => $q->where('user_id', $user->id))
                : $query->whereDoesntHave('enrollments', fn (Builder $q) => $q->where('user_id', $user->id));
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn (Builder $q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('excerpt', 'like', "%{$search}%"));
        }

        $this->applySort($request, $query);

        $courses = $query->paginate($this->perPage($request))->withQueryString();

        $this->attachBookmarks($request, collect($courses->items()));

        return CourseResource::collection($courses);
    }

    public function show(Request $request, Course $course): CourseResource
    {
        abort_unless($course->status === 'published', 404);

        $user = $request->user();

        $course->load([
            'category',
            'instructor',
            'enrollments' => fn ($q) => $q->where('user_id', $user->id),
        ]);
        $course->loadCount(['modules', 'lessons', 'enrollments as students_count']);

        if ($instructor = $course->instructor) {
            $taughtCourseIds = Course::published()->where('instructor_id', $instructor->id)->pluck('id');
            $instructor->setAttribute('include_detail', true);
            $instructor->setAttribute('courses_count', $taughtCourseIds->count());
            $instructor->setAttribute('students_count', Enrollment::whereIn('course_id', $taughtCourseIds)->count());
        }

        $this->attachBookmarks($request, collect([$course]));

        return new CourseResource($course);
    }

    public function enroll(Request $request, Course $course)
    {
        abort_unless($course->status === 'published', 404);

        $enrollment = Enrollment::firstOrCreate(
            ['user_id' => $request->user()->id, 'course_id' => $course->id],
            ['enrolled_at' => now()],
        );

        return (new EnrollmentResource($enrollment))
            ->response($request)
            ->setStatusCode($enrollment->wasRecentlyCreated ? 201 : 200);
    }

    protected function applySort(Request $request, Builder $query): void
    {
        $sort = (string) $request->query('sort', '-published_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, ['title', 'published_at', 'created_at', 'rating', 'price', 'duration_minutes'], true)) {
            $column = 'published_at';
            $direction = 'desc';
        }

        $query->orderBy($column, $direction)->orderBy('id');
    }

    /** @param  Collection<int, Course>  $courses */
    protected function attachBookmarks(Request $request, Collection $courses): void
    {
        $bookmarked = Bookmark::where('user_id', $request->user()->id)
            ->where('bookmarkable_type', Course::class)
            ->whereIn('bookmarkable_id', $courses->pluck('id'))
            ->pluck('bookmarkable_id')
            ->flip();

        $courses->each(fn (Course $course) => $course->setAttribute(
            'is_bookmarked',
            $bookmarked->has($course->id),
        ));
    }
}
