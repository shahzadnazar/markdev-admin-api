<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\RestrictsToInstructor;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Module;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ModuleController extends Controller
{
    use RestrictsToInstructor;

    public function store(Request $request, Course $course): RedirectResponse
    {
        $this->authorizeCourseAccess($request, $course->id);
        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);

        $course->modules()->create([
            'title' => $data['title'],
            'position' => ((int) $course->modules()->max('position')) + 1,
        ]);

        return back()->with('success', 'Module added.');
    }

    public function update(Request $request, Module $module): RedirectResponse
    {
        $this->authorizeCourseAccess($request, $module->course_id);
        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);

        $module->update($data);

        return back()->with('success', 'Module renamed.');
    }

    /** Reorder with up/down buttons: swap positions with the neighbour. */
    public function move(Request $request, Module $module): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);

        $neighbour = Module::where('course_id', $module->course_id)
            ->when($data['direction'] === 'up',
                fn ($query) => $query->where('position', '<', $module->position)->orderByDesc('position'),
                fn ($query) => $query->where('position', '>', $module->position)->orderBy('position'))
            ->first();

        if ($neighbour) {
            [$module->position, $neighbour->position] = [$neighbour->position, $module->position];
            $module->save();
            $neighbour->save();
        }

        return back();
    }

    public function destroy(Module $module): RedirectResponse
    {
        $this->authorizeCourseAccess(request(), $module->course_id);
        $title = $module->title;
        $module->lessons()->delete();
        $module->delete();

        return back()->with('success', "Module \"{$title}\" and its lessons deleted.");
    }
}
