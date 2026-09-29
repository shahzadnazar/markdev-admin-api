<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\TeamCalendar;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The month view: every date the team portal already knows about.
 *
 * Reads and nothing else. There is no store, no update and no destroy here, and
 * that is not an omission — the calendar is DERIVED from the projects, the
 * milestones, the tasks, the leave and the holidays, so there is nothing on it
 * to create. A date is changed where it lives.
 *
 * Gated on `tasks.view`, the whole-portal gate, because everybody in the portal
 * has dates. What each of them SEES is decided by the existing scopes inside
 * TeamCalendar, not here: no filtering happens in this controller, which is the
 * same rule the comment and file screens follow.
 */
class TeamCalendarController extends Controller
{
    public function index(Request $request): View
    {
        $viewer = $request->user();

        // A bad or missing month is this month rather than an error: a calendar
        // asked for nonsense should still be a calendar.
        $month = rescue(
            fn () => Carbon::createFromFormat('Y-m', (string) $request->query('month'))->startOfMonth(),
            fn () => Carbon::today()->startOfMonth(),
            false,
        );

        // resolveScope, not the raw parameter: a team member typing scope=all
        // gets their first allowed toggle, and never a 403 over a preference.
        $scope = TeamCalendar::resolveScope($viewer, $request->query('scope'));
        $days = TeamCalendar::month($viewer, $month, $scope);

        return view('admin.calendar.index', [
            'month' => $month,
            'scope' => $scope,
            'scopes' => TeamCalendar::scopes($viewer),
            'days' => $days,
            'legend' => TeamCalendar::legend($days),
            // The grid starts on the Monday of the week the 1st falls in, so a
            // month never begins mid-row. ISO weekdays throughout, matching
            // AcademyCalendar and the slots' `days` column.
            'gridStart' => $month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY),
            'gridEnd' => $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY),
        ]);
    }
}
