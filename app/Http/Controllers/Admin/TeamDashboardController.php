<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\TeamDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The team portal's front door — one screen, three audiences.
 *
 * ONE route, one controller, one view. Not three: a member, a lead and an admin
 * see different figures, and the difference is which rows the EXISTING scopes
 * return them, not which controller they hit. Three screens would be three
 * places to forget a scope.
 *
 * Nothing here filters anything. Every figure comes from App\Support\TeamDashboard,
 * which runs Task::visibleTo, Project::visibleTo and the team-membership
 * subquery — the same filters the lists, the board and the calendar use.
 *
 * Gated on `team-dashboard.view`, its own permission rather than the academy's
 * `dashboard.view`: that one is held by manager and instructor, and reusing it
 * would have put this screen in front of both.
 */
class TeamDashboardController extends Controller
{
    public function index(Request $request): View
    {
        // A bad or missing month is this month rather than an error, the same
        // rule the calendar follows: a dashboard asked for nonsense should
        // still be a dashboard.
        $month = rescue(
            fn () => Carbon::createFromFormat('Y-m', (string) $request->query('month'))->startOfMonth(),
            fn () => Carbon::today()->startOfMonth(),
            false,
        );

        $viewer = $request->user();

        return view('admin.team-dashboard.index', [
            'viewer' => $viewer,
            'data' => TeamDashboard::for($viewer, $month),
            'teamCount' => TeamDashboard::teamCountFor($viewer),
        ]);
    }
}
