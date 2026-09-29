<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryScoreRecord;
use App\Models\Task;
use App\Models\Team;
use App\Services\DeliveryScoreCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * One team's delivery scores — a LIST screen, so it reads the cache.
 *
 * The precedent is the admin student list reading enrollments.progress_percent
 * rather than recomputing: twelve members would otherwise be twelve live
 * calculations for one page. A person's OWN score is computed live, on the
 * tasks index, and never reads these rows.
 *
 * Gated on `teams.view`, which a team member does not hold. Somebody else's
 * score is somebody else's business; a lead needs it to run the team.
 */
class TeamScoreController extends Controller
{
    public function show(Request $request, Team $team, DeliveryScoreCache $cache): View
    {
        $this->assertVisible($request, $team);

        $members = $team->members()->orderBy('name')->get(['users.id', 'users.name']);

        // ONE query for every member's figure, which is the whole point of the
        // cache. A missing row means nobody has recomputed for that person yet
        // — `delivery:recache` fills them in — and reads as no score, which is
        // the same thing the calculator would say about someone with no stints.
        $scores = DeliveryScoreRecord::whereIn('user_id', $members->pluck('id'))->get()->keyBy('user_id');

        return view('admin.teams.scores', [
            'team' => $team,
            'members' => $members,
            'scores' => $scores,
        ]);
    }

    /** Recompute this team's cached figures on demand. */
    public function refresh(Request $request, Team $team, DeliveryScoreCache $cache): RedirectResponse
    {
        $this->assertVisible($request, $team);

        $team->members()->get()->each(fn ($member) => $cache->refresh($member));

        return back()->with('success', 'Scores recomputed.');
    }

    protected function assertVisible(Request $request, Team $team): void
    {
        $user = $request->user();

        // An admin sees every team; a lead sees the teams they are a member of,
        // which is the same rule projects and tasks use.
        abort_unless(
            $user->can(Task::FULL_VIEW_PERMISSION)
                || $team->hasMember($user->getKey()),
            404,
        );
    }
}
