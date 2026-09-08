<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\Category;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Keeps an instructor's leave and attendance screens inside their own field.
 *
 * An instructor is tied to a category through the courses they teach —
 * `courses.instructor_id` for the instructor, `courses.category_id` for the
 * field — and a student belongs to a category through the courses they are
 * enrolled in. Both ends already existed, so nothing was added to `users`:
 *
 *     instructor -> courses.instructor_id -> courses.category_id -> categories
 *     student    -> enrollments.course_id -> courses.category_id -> categories
 *
 * An instructor may teach across several categories and then sees all of them,
 * which falls out of the query rather than needing a rule of its own.
 *
 * `courses.category_id` is nullable, and that decides the safe direction here:
 * an uncategorised course contributes no category, so an instructor who only
 * teaches uncategorised courses sees nobody, and a student enrolled only in
 * uncategorised courses is visible to admins alone. An uncategorised course
 * must never become a skeleton key.
 *
 * Every check is on the query or on the record being acted on, never on
 * whether a link is drawn: a URL, an id or a crafted POST for another
 * category's student is a 403, not an empty page.
 */
trait ScopesToCategory
{
    /**
     * Category ids this user's view is limited to, or null when unrestricted.
     *
     * Null for super-admin, admin and manager — they see every category, and
     * returning an id list for them would silently scope them the day someone
     * gave a manager a course to teach.
     *
     * @return array<int, int>|null
     */
    protected function managedCategoryIds(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null || $user->hasAnyRole(['super-admin', 'admin', 'manager'])) {
            return null;
        }

        return Course::query()
            ->where('instructor_id', $user->id)
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** True when this user only sees part of the academy. */
    protected function isCategoryScoped(Request $request): bool
    {
        return $this->managedCategoryIds($request) !== null;
    }

    /**
     * Limit a query on `users` to students inside these categories.
     *
     * An empty id list matches nobody, which is the whole point: an instructor
     * with no categorised course must not fall through to seeing everyone.
     *
     * @param  array<int, int>|null  $categoryIds
     */
    protected function scopeUsersToCategories(Builder $query, ?array $categoryIds): Builder
    {
        if ($categoryIds === null) {
            return $query;
        }

        return $query->whereHas('enrollments', fn (Builder $enrollment) => $enrollment
            ->whereHas('course', fn (Builder $course) => $course->whereIn('category_id', $categoryIds)));
    }

    /** Whether this student is inside the categories this user may see. */
    protected function studentInCategories(User $student, ?array $categoryIds): bool
    {
        if ($categoryIds === null) {
            return true;
        }

        if ($categoryIds === []) {
            return false;
        }

        return $student->enrollments()
            ->whereHas('course', fn (Builder $course) => $course->whereIn('category_id', $categoryIds))
            ->exists();
    }

    /**
     * 403 unless this user may act on this student.
     *
     * 403 rather than 404: the instructor is a member of staff asking about a
     * real student, and telling them the record does not exist would be a lie
     * they would waste time on.
     */
    protected function authorizeStudentCategory(Request $request, User $student): void
    {
        abort_unless(
            $this->studentInCategories($student, $this->managedCategoryIds($request)),
            403,
            'This student is in another category.',
        );
    }

    /**
     * The categories this user teaches in, for showing which is which.
     *
     * Empty for an unrestricted user — admins have no "own" categories, and
     * the screens use that to decide whether to label rows at all.
     *
     * @return \Illuminate\Support\Collection<int, Category>
     */
    protected function managedCategories(Request $request)
    {
        $ids = $this->managedCategoryIds($request);

        if ($ids === null || $ids === []) {
            return collect();
        }

        // Deduplicated by name: two categories can share one, and "Web
        // Development and Web Development" tells a reader nothing.
        return Category::whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->unique('name')
            ->values();
    }

    /**
     * Which of this user's categories each of these students belongs to.
     *
     * Only worth showing when an instructor teaches in more than one: with a
     * single category every row carries the same label and it says nothing.
     *
     * @param  \Illuminate\Support\Collection<int, int>|array<int, int>  $studentIds
     * @return \Illuminate\Support\Collection<int, string>  user id => names
     */
    protected function categoryLabelsFor(Request $request, $studentIds)
    {
        $ids = $this->managedCategoryIds($request);
        $studentIds = collect($studentIds)->values();

        // Judged on distinct names, not ids: if every row would carry the same
        // word the label says nothing, and two categories sharing a name are
        // one word to a reader.
        if ($ids === null || $studentIds->isEmpty() || $this->managedCategories($request)->count() < 2) {
            return collect();
        }

        return \App\Models\Enrollment::query()
            ->whereIn('enrollments.user_id', $studentIds)
            ->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->join('categories', 'categories.id', '=', 'courses.category_id')
            ->whereIn('courses.category_id', $ids)
            ->distinct()
            ->get(['enrollments.user_id as user_id', 'categories.name as name'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('name')->unique()->sort()->implode(', '));
    }
}
