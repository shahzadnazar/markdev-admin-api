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
        // Trimmed before validating, not after: otherwise "Morning" and
        // "Morning " are two different names to the uniqueness check and only
        // become the same once they are already both in the table.
        $request->merge(['name' => trim((string) $request->input('name'))]);

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

        $this->assertNameIsFree($data['name'], $slot);
        $this->assertTimesAreFree($start, $end, $slot);

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
     * One slot, one name.
     *
     * A second "Morning" tells an admin nothing about which one a student is
     * on, and the registration dropdown shows both identically.
     */
    protected function assertNameIsFree(string $name, ?AttendanceSlot $slot): void
    {
        // Editing a slot without touching its name always passes. Slots
        // created before this rule existed may already share one, and being
        // unable to fix such a slot's times because of its name helps nobody.
        if ($slot && AttendanceSlot::normaliseName($slot->name) === AttendanceSlot::normaliseName($name)) {
            return;
        }

        $taken = AttendanceSlot::query()
            ->when($slot, fn ($query) => $query->whereKeyNot($slot->getKey()))
            // lower() on both sides rather than Rule::unique, whose comparison
            // follows the column collation: case-insensitive on MariaDB but
            // case-sensitive on SQLite, so "morning" would slip past there.
            ->whereRaw('lower(name) = ?', [AttendanceSlot::normaliseName($name)])
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => "A slot named \"{$name}\" already exists.",
            ]);
        }
    }

    /**
     * No two slots may cover the same minute.
     *
     * Each slot carries its own lateness rule, so a student punching in during
     * a shared minute would be judged by two of them with no way to say which
     * is right.
     */
    protected function assertTimesAreFree(string $start, string $end, ?AttendanceSlot $slot): void
    {
        // Saving a slot without moving it always passes, for the same reason
        // the name check lets an unchanged name through: a pair of slots that
        // already overlap must not lock each other out of every other field.
        if ($slot
            && Carbon::parse($slot->start_time)->format('H:i:s') === $start
            && Carbon::parse($slot->end_time)->format('H:i:s') === $end) {
            return;
        }

        $conflict = AttendanceSlot::query()
            ->when($slot, fn ($query) => $query->whereKeyNot($slot->getKey()))
            // Strictly < and >, because end_time is the moment a slot closes
            // rather than a minute inside it: 9-11 and 11-1 run back to back,
            // which is the ordinary academy timetable, not an overlap. This
            // one condition covers every shape -- starting inside, ending
            // inside, contained, containing and identical.
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            // Inactive slots count. Students stay assigned to a deactivated
            // slot, so its lateness rule is still deciding their attendance.
            // Soft-deleted ones do not; the global scope drops them.
            ->orderBy('start_time')
            ->first();

        if ($conflict) {
            throw ValidationException::withMessages([
                'start_time' => "Overlaps \"{$conflict->label()}\". Slots cannot share the same time.",
            ]);
        }
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
