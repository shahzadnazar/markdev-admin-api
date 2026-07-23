<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\Course;
use Illuminate\Http\Request;

/**
 * Instructors work inside their own classroom only: every course-linked
 * query in the panel is limited to courses they teach, while admins,
 * managers and the super admin stay unrestricted.
 */
trait RestrictsToInstructor
{
    /** Course ids the current user may manage, or null when unrestricted. */
    protected function managedCourseIds(Request $request): ?array
    {
        $user = $request->user();

        if ($user->hasAnyRole(['super-admin', 'admin', 'manager'])) {
            return null;
        }

        return Course::where('instructor_id', $user->id)->pluck('id')->all();
    }

    /** 403 unless the current user may manage the given course. */
    protected function authorizeCourseAccess(Request $request, ?int $courseId): void
    {
        $ids = $this->managedCourseIds($request);

        if ($ids !== null && ! in_array($courseId, $ids, true)) {
            abort(403, 'This course belongs to another instructor.');
        }
    }

    /** Courses selectable in filters/forms for the current user. */
    protected function selectableCourses(Request $request)
    {
        return Course::query()
            ->when(
                ($ids = $this->managedCourseIds($request)) !== null,
                fn ($query) => $query->whereIn('id', $ids),
            )
            ->orderBy('title');
    }
}
