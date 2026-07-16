<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\LearningActivity;
use App\Models\PointEvent;
use App\Models\User;
use App\Services\LearningStatsService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LeaderboardController extends ApiController
{
    public function __invoke(Request $request, LearningStatsService $stats): JsonResponse
    {
        $request->validate([
            'period' => ['sometimes', 'string', 'in:weekly,monthly,all_time'],
        ]);

        $period = (string) $request->query('period', 'all_time');
        $user = $request->user();

        $start = match ($period) {
            'weekly' => now()->startOfWeek(),
            'monthly' => now()->startOfMonth(),
            default => null,
        };

        $query = User::role('student')
            ->withCount(['enrollments as courses_completed' => fn ($q) => $q->whereNotNull('completed_at')]);

        if ($start !== null) {
            $query->withSum(
                ['pointEvents as period_points' => fn ($q) => $q->where('created_at', '>=', $start)],
                'points',
            )->orderByRaw('coalesce(period_points, 0) desc');
        } else {
            $query->orderByDesc('points');
        }

        $top = $query->orderBy('id')->take(10)->get();

        $streaks = $this->streaksFor($top->pluck('id')->push($user->id)->unique(), $stats);

        $entries = $top->values()->map(fn (User $entrant, int $index) => $this->entry(
            $entrant,
            $index + 1,
            $start,
            $streaks,
            $entrant->id === $user->id,
        ));

        $me = $entries->firstWhere('is_me', true);

        if ($me === null && $user->hasRole('student')) {
            $mine = $user->loadCount(['enrollments as courses_completed' => fn ($q) => $q->whereNotNull('completed_at')]);

            if ($start !== null) {
                $mine->setAttribute('period_points', (int) PointEvent::where('user_id', $user->id)
                    ->where('created_at', '>=', $start)
                    ->sum('points'));
            }

            $me = $this->entry($mine, $this->rankOf($user, $start), $start, $streaks, true);
        }

        return response()->json([
            'data' => [
                'period' => $period,
                'entries' => $entries->values(),
                'me' => $me,
            ],
        ]);
    }

    /** @param  Collection<string, int>  $streaks */
    protected function entry(User $user, int $rank, ?CarbonInterface $start, Collection $streaks, bool $isMe): array
    {
        return [
            'rank' => $rank,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar_url' => $user->avatar_url,
            ],
            'points' => $start !== null ? (int) ($user->period_points ?? 0) : (int) $user->points,
            'courses_completed' => (int) ($user->courses_completed ?? 0),
            'streak_days' => (int) ($streaks[$user->id] ?? 0),
            'is_me' => $isMe,
        ];
    }

    /** The caller's true rank among students for the period. */
    protected function rankOf(User $user, ?CarbonInterface $start): int
    {
        if ($start === null) {
            return User::role('student')->where('points', '>', $user->points)->count() + 1;
        }

        $mine = (int) PointEvent::where('user_id', $user->id)->where('created_at', '>=', $start)->sum('points');

        return User::role('student')
            ->whereRaw(
                '(select coalesce(sum(points), 0) from point_events where point_events.user_id = users.id and point_events.created_at >= ?) > ?',
                [$start, $mine],
            )
            ->count() + 1;
    }

    /**
     * Current streaks for a set of users out of one activity query.
     *
     * @param  Collection<int, int>  $userIds
     * @return Collection<int, int>
     */
    protected function streaksFor(Collection $userIds, LearningStatsService $stats): Collection
    {
        $byUser = LearningActivity::whereIn('user_id', $userIds)
            ->where('minutes', '>', 0)
            ->get()
            ->groupBy('user_id');

        return $byUser->map(fn ($activities) => $stats->currentStreakFromDates(
            $activities->mapWithKeys(fn ($activity) => [$activity->date->toDateString() => true]),
        ));
    }
}
