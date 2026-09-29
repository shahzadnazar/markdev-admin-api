<?php

namespace App\Support;

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\User;
use App\Notifications\Contracts\CarriesItsSubject;
use App\Notifications\MilestoneDueTomorrow;
use App\Notifications\ProjectOverdue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The two notices nobody's own click can produce: a deadline arriving.
 *
 * Events 1 to 5 of this phase fire inline from the write that causes them — a
 * mention, an assignment, a review, a charge — because there is a request there
 * to hang them on and they cost nothing. These two have no write behind them at
 * all: a milestone due tomorrow is due tomorrow because the clock moved, and
 * nobody touched anything. So they are a daily pass, built the way
 * AnnounceUpcomingHolidays is built.
 *
 * ## Nothing fires when the date is set
 *
 * A milestone entered in January for a March deadline is silent until the day
 * before, exactly as a holiday typed months ahead is. A notice that arrives
 * three weeks early is not a notice anybody is still looking at.
 *
 * ## Idempotent on the DATA, not on a timestamp
 *
 * Each notice carries a subject — "milestone:17:due:2026-10-06" — and
 * PortalNotifier looks for the stored row before writing another. So the second
 * run on one morning sends nothing, a re-run after a failure is safe, and a
 * deadline that is MOVED and comes round again is a new subject and a new notice,
 * because it is a new fact. No window, no "have we run today" flag, nothing that
 * a clock skew or a missed night could get wrong.
 *
 * ## Who is told
 *
 * The project's TEAM, and only them. Not every admin: an admin who wants the
 * whole picture has the calendar, and a per-project notice to everybody with
 * `clients.view` is precisely the list people learn to scroll past. Inactive
 * accounts are skipped, as the holiday announcer skips them.
 *
 * Every send goes through PortalNotifier, so a recipient who cannot see the
 * project is not told about it — which here is belt and braces, since the
 * recipients ARE the project's team, and is applied anyway because that is
 * where the rule lives.
 */
final class TeamDeadlineNotifier
{
    /**
     * Send both notices for one day.
     *
     * @return array{milestones: int, overdue: int, skipped: int}
     */
    public function run(?Carbon $today = null, bool $dryRun = false): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        $milestones = $this->milestonesDueTomorrow($today);
        $overdue = $this->projectsGoneOverdue($today);

        $sent = ['milestones' => 0, 'overdue' => 0, 'skipped' => 0];

        foreach ($milestones as $milestone) {
            foreach ($this->recipients($milestone->project) as $member) {
                $this->deliver(
                    $member,
                    $milestone->project,
                    new MilestoneDueTomorrow($milestone),
                    $dryRun,
                    $sent,
                    'milestones',
                );
            }
        }

        foreach ($overdue as $project) {
            $late = (int) Carbon::parse($project->due_date)->startOfDay()->diffInDays($today);

            foreach ($this->recipients($project) as $member) {
                $this->deliver(
                    $member,
                    $project,
                    new ProjectOverdue($project, $late),
                    $dryRun,
                    $sent,
                    'overdue',
                );
            }
        }

        return $sent;
    }

    /**
     * Milestones falling due tomorrow and not already done.
     *
     * `onDate` rather than an equality on `due_date`: it is a date-cast column,
     * and this codebase has paid for that mistake ten times — see ScopesToDay.
     *
     * @return Collection<int, ProjectMilestone>
     */
    public function milestonesDueTomorrow(Carbon $today): Collection
    {
        return ProjectMilestone::query()
            ->outstanding()
            ->onDate($today->copy()->addDay())
            ->with(['project.team.members:id,name,is_active'])
            ->get();
    }

    /**
     * Projects past their due date whose schedule is actually running.
     *
     * `countingDays` is the same rule daysRemaining() returns null for, read
     * once rather than re-derived: a PAUSED project is not consuming its
     * schedule, so it is not late — charging a team for time it was told to stop
     * working is the thing phase 2 wrote that scope to prevent — and a closed
     * one has no schedule left to be late against.
     *
     * @return Collection<int, Project>
     */
    public function projectsGoneOverdue(Carbon $today): Collection
    {
        return Project::query()
            ->countingDays()
            ->whereNotNull('due_date')
            ->beforeDate($today)
            ->with(['team.members:id,name,is_active'])
            ->get();
    }

    /**
     * The team on a piece of work, active accounts only.
     *
     * @return Collection<int, User>
     */
    protected function recipients(?Project $project): Collection
    {
        return ($project?->team?->members ?? collect())
            ->filter(fn (User $member) => (bool) $member->is_active)
            ->values();
    }

    /**
     * Send one notice, or count why it was not sent.
     *
     * "Skipped" covers both reasons a notice does not go out — already told,
     * and not entitled to know — because the command reports a number and the
     * distinction belongs in neither of its two counters. Which one it was is
     * always answerable from the notifications table itself.
     *
     * @param  array{milestones: int, overdue: int, skipped: int}  $sent
     */
    protected function deliver(User $member, Project $project, Notification&CarriesItsSubject $notification, bool $dryRun, array &$sent, string $bucket): void
    {
        if ($dryRun) {
            // Asked in the same order and with the same questions a real run
            // asks, so a dry run reports the numbers that run would produce
            // rather than claiming every notice is new.
            $would = PortalNotifier::mayBeTold($member, $project)
                && ! PortalNotifier::alreadyTold($member, $notification);

            $would ? $sent[$bucket]++ : $sent['skipped']++;

            return;
        }

        PortalNotifier::notify($member, $project, $notification)
            ? $sent[$bucket]++
            : $sent['skipped']++;
    }
}
