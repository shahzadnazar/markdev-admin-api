<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSlot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Manages the parts of the teaching day students are admitted into.
 *
 * A slot is a time of day that repeats — it never stores a date, and nobody
 * types one. Admins enter times in 12-hour form with an AM/PM selector; they
 * are stored as 24-hour TIME values and shown back in 12-hour everywhere.
 */
class AttendanceSlotController extends Controller
{
    public function index(): View
    {
        return view('admin.attendance-slots.index', [
            'slots' => AttendanceSlot::query()
                ->withCount('studentProfiles')
                ->ordered()
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.attendance-slots.form', ['slot' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        AttendanceSlot::create($data);

        return redirect()->route('admin.attendance-slots.index')
            ->with('success', "Slot \"{$data['name']}\" created.");
    }

    public function edit(AttendanceSlot $attendanceSlot): View
    {
        return view('admin.attendance-slots.form', ['slot' => $attendanceSlot]);
    }

    public function update(Request $request, AttendanceSlot $attendanceSlot): RedirectResponse
    {
        $attendanceSlot->update($this->validated($request, $attendanceSlot));

        return redirect()->route('admin.attendance-slots.index')
            ->with('success', "Slot \"{$attendanceSlot->name}\" updated.");
    }

    /** Soft delete — students assigned to the slot simply lose the assignment. */
    public function destroy(AttendanceSlot $attendanceSlot): RedirectResponse
    {
        $name = $attendanceSlot->name;
        $assigned = $attendanceSlot->studentProfiles()->count();
        $attendanceSlot->delete();

        $note = $assigned > 0
            ? " {$assigned} student(s) fall back to the academy day start until reassigned."
            : '';

        return redirect()->route('admin.attendance-slots.index')
            ->with('success', "Slot \"{$name}\" deleted.".$note);
    }

    /** Stop offering a slot to new students without disturbing existing ones. */
    public function toggle(AttendanceSlot $attendanceSlot): RedirectResponse
    {
        $attendanceSlot->update(['is_active' => ! $attendanceSlot->is_active]);

        return back()->with('success', $attendanceSlot->is_active
            ? "\"{$attendanceSlot->name}\" is offered to new students again."
            : "\"{$attendanceSlot->name}\" is no longer offered. Students already on it keep it.");
    }

    /** Reorder by swapping with the neighbour, as modules and lessons do. */
    public function move(Request $request, AttendanceSlot $attendanceSlot): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);

        $neighbour = AttendanceSlot::query()
            ->when($data['direction'] === 'up',
                fn ($query) => $query->where('sort_order', '<', $attendanceSlot->sort_order)->orderByDesc('sort_order'),
                fn ($query) => $query->where('sort_order', '>', $attendanceSlot->sort_order)->orderBy('sort_order'))
            ->first();

        if ($neighbour) {
            [$attendanceSlot->sort_order, $neighbour->sort_order] = [$neighbour->sort_order, $attendanceSlot->sort_order];
            $attendanceSlot->save();
            $neighbour->save();
        }

        return back();
    }

    /* ------------------------------- Helpers ------------------------------- */

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?AttendanceSlot $slot = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'start_time_hour' => ['required', 'integer', 'min:1', 'max:12'],
            'start_time_minute' => ['required', 'integer', 'min:0', 'max:59'],
            'start_time_meridiem' => ['required', Rule::in(['AM', 'PM'])],
            'end_time_hour' => ['required', 'integer', 'min:1', 'max:12'],
            'end_time_minute' => ['required', 'integer', 'min:0', 'max:59'],
            'end_time_meridiem' => ['required', Rule::in(['AM', 'PM'])],
            'late_after_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'start_time_hour' => 'start time', 'start_time_minute' => 'start time', 'start_time_meridiem' => 'start time',
            'end_time_hour' => 'end time', 'end_time_minute' => 'end time', 'end_time_meridiem' => 'end time',
        ]);

        $start = $this->toStoredTime($data, 'start_time');
        $end = $this->toStoredTime($data, 'end_time');

        // Slots live inside one day, so an end that is not later than the start
        // describes a session crossing midnight — which is not a thing here.
        if ($end <= $start) {
            throw ValidationException::withMessages([
                'end_time' => 'End time must be after start time.',
            ]);
        }

        return [
            'name' => $data['name'],
            'start_time' => $start,
            'end_time' => $end,
            'late_after_minutes' => (int) $data['late_after_minutes'],
            'is_active' => (bool) ($data['is_active'] ?? false),
            // New slots land at the end of the list; editing keeps its place.
            'sort_order' => $slot?->sort_order ?? ((int) AttendanceSlot::max('sort_order') + 1),
        ];
    }

    /**
     * Fold the 12-hour parts the admin picked into a stored 24-hour TIME.
     *
     * Nothing but "HH:MM:SS" ever reaches the database — the AM/PM wording is
     * an input and display concern only.
     *
     * @param  array<string, mixed>  $data
     */
    protected function toStoredTime(array $data, string $field): string
    {
        return Carbon::createFromFormat(
            'g:i A',
            sprintf('%d:%02d %s', $data[$field.'_hour'], $data[$field.'_minute'], $data[$field.'_meridiem']),
        )->format('H:i:s');
    }
}
