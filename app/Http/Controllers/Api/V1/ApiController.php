<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    protected const MAX_PER_PAGE = 100;

    protected function perPage(Request $request, int $default = 15): int
    {
        return min(max((int) $request->input('per_page', $default), 1), self::MAX_PER_PAGE);
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    protected function enrolledCourseIds(Request $request): \Illuminate\Support\Collection
    {
        return $request->user()->enrollments()->pluck('course_id');
    }
}
