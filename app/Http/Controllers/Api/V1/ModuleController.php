<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ModuleResource;
use App\Models\Course;
use App\Models\LessonCompletion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ModuleController extends ApiController
{
    public function index(Request $request, Course $course): AnonymousResourceCollection
    {
        abort_unless($course->status === 'published', 404);

        $modules = $course->modules()->with('lessons')->get();

        // One query for the user's completions across the whole course.
        $completed = LessonCompletion::where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->pluck('lesson_id')
            ->flip();

        foreach ($modules as $module) {
            foreach ($module->lessons as $lesson) {
                $lesson->setAttribute('is_completed', $completed->has($lesson->id));
            }
        }

        return ModuleResource::collection($modules);
    }
}
