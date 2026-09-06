<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Support\AcademyCalendar;
use App\Support\HolidayAnnouncer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Dates the academy is closed that no weekly pattern covers.
 *
 * The weekly pattern is `academy_working_days` in Settings; this is for Eid,
 * 14 August and anything else that moves or happens once. A holiday closes the
 * academy for everybody — it beats a slot's own weekday list — so nobody is
 * expected, nobody is absent and nobody is fined.
 *
 * Eid runs several days, so create accepts a range and writes one row per
 * date: every question asked of this table is "is this date a holiday?", and
 * one row per date keeps that a lookup rather than arithmetic.
 */
class HolidayController extends Controller
{
    /** How far a single range may stretch, so a typo cannot fill the table. */
    protected const MAX_RANGE_DAYS = 31;

    public function __construct(protected HolidayAnnouncer $announcer)
    {
    }

    public function index(Request $request): View
    {
        $year = (int) ($request->query('year') ?: today()->year);

        return view('admin.holidays.index', [
            'year' => $year,
            'holidays' => Holiday::query()
                ->betweenDates(Carbon::create($year, 1, 1), Carbon::create($year, 12, 31))
                ->orderBy('date')
                ->get(),
            // Every year that has a holiday, plus this one and the next, so an
            // admin can always get to the year they are planning.
            'years' => Holiday::query()->pluck('date')
                ->map(fn ($date) => (int) Carbon::parse($date)->year)
                ->push(today()->year)
                ->push(today()->year + 1)
                ->unique()->sort()->values()->all(),
            'workingDaysLabel' => AcademyCalendar::workingDaysLabel(),
        ]);
    }

    public function create(): View
    {
        return view('admin.holidays.form', ['holiday' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'date' => ['required', 'date'],
            // Eid is three days and Muharram is two, so a range is the normal
            // case rather than a convenience. Left blank it is a single day.
            'to_date' => ['nullable', 'date', 'after_or_equal:date'],
        ], [
            'to_date.after_or_equal' => 'The last day cannot be before the first.',
        ]);

        $name = trim($data['name']);
        $from = Carbon::parse($data['date'])->startOfDay();
        $to = Carbon::parse($data['to_date'] ?? $data['date'])->startOfDay();

        if ($from->diffInDays($to) + 1 > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'to_date' => 'A holiday range is limited to '.self::MAX_RANGE_DAYS.' days — add a second one if it really is longer.',
            ]);
        }

        $dates = collect();
        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $dates->push($day->copy());
        }

        // Matched in PHP on Y-m-d: `date` is a date-cast column, so a whereIn
        // of date strings finds nothing on SQLite, and the unique index would
        // then be hit as an exception instead of a message.
        $taken = AcademyCalendar::holidayMap($from, $to);

        if ($taken->isNotEmpty()) {
            throw ValidationException::withMessages([
                'date' => $taken->count() === 1
                    ? 'That date is already a holiday: '.$taken->first().'.'
                    : $taken->count().' of those dates are already holidays.',
            ]);
        }

        DB::transaction(function () use ($dates, $name) {
            foreach ($dates as $day) {
                Holiday::create(['date' => $day->toDateString(), 'name' => $name]);
            }
        });

        $note = $dates->count() === 1
            ? ''
            : " ({$dates->count()} days, {$from->format('j M')} – {$to->format('j M Y')})";

        return redirect()->route('admin.holidays.index', ['year' => $from->year])
            ->with('success', "Holiday \"{$name}\" added.".$note);
    }

    public function edit(Holiday $holiday): View
    {
        return view('admin.holidays.form', ['holiday' => $holiday]);
    }

    /** One row at a time: a range is a create-time convenience, not a shape. */
    public function update(Request $request, Holiday $holiday): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'date' => ['required', 'date'],
        ]);

        $date = Carbon::parse($data['date'])->startOfDay();

        $clash = Holiday::onDate($date)->where('id', '!=', $holiday->id)->first();

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'date' => 'That date is already a holiday: '.$clash->name.'.',
            ]);
        }

        $wasOn = $holiday->date->copy();
        $holiday->update(['name' => trim($data['name']), 'date' => $date->toDateString()]);

        // A notice already sent for this closure now names the wrong dates, or
        // describes a closure the edit has broken in two. Both ends of the
        // move are reconciled, since either could carry the notice.
        $this->announcer->reconcileAround(min($wasOn, $date), max($wasOn, $date));

        return redirect()->route('admin.holidays.index', ['year' => $date->year])
            ->with('success', "Holiday \"{$holiday->name}\" updated.");
    }

    /**
     * Soft delete.
     *
     * Register rows already written for the day keep their `holiday` status —
     * rewriting settled history would turn days people have seen into
     * absences, and an absence is billable. From here on the date is an
     * ordinary working day.
     */
    public function destroy(Holiday $holiday): RedirectResponse
    {
        $name = $holiday->name;
        $date = $holiday->date->copy();
        $holiday->delete();

        // The register keeps what it settled, but a notice saying the academy
        // will be closed is a claim about a day that is now an ordinary
        // working one, so it comes down. Withdrawn rather than erased: the
        // announcement is soft-deleted like the holiday itself.
        $withdrawn = $this->announcer->reconcileAround($date, $date)
            ->where('action', 'withdrew')
            ->isNotEmpty();

        return redirect()->route('admin.holidays.index', ['year' => $date->year])
            ->with('success', "Holiday \"{$name}\" removed. Days already settled keep the status they were given."
                .($withdrawn ? ' The announcement for it has been withdrawn.' : ''));
    }
}
