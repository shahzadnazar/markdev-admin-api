<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\Holiday;
use App\Models\User;
use App\Notifications\AnnouncementPublished;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posts, updates and takes down the notice for a holiday closure.
 *
 * One notice per closure, not per date: HolidayRange puts the rows back
 * together first. The notice is an ordinary academy-wide Announcement carrying
 * `holiday_id`, so it reaches the portal feed, the staff ticker and the
 * notification bell through the paths that already exist — there is no second
 * notification system here.
 *
 * `holiday_id` points at the first day of the closure, and that is what makes
 * every operation here idempotent: a second run on the same morning finds the
 * notice it already sent, a moved holiday finds the notice to rewrite, and a
 * removed one finds the notice to take down.
 */
class HolidayAnnouncer
{
    /**
     * Post the notice for a closure, or return the one already posted.
     *
     * Returns null only when there is nobody to post as.
     */
    public function announce(HolidayRange $range, ?Carbon $asOf = null): ?Announcement
    {
        if ($existing = $this->announcementFor($range->first())) {
            return $this->sync($existing, $range);
        }

        $author = $this->author();

        if ($author === null) {
            return null;
        }

        $announcement = DB::transaction(function () use ($range, $author, $asOf) {
            return Announcement::create([
                'author_id' => $author->id,
                // Academy-wide on purpose: a closure is not a fact about one
                // course, and scoping it to one would hide it from everybody
                // else.
                'course_id' => null,
                'holiday_id' => $range->first()->id,
                'title' => $range->headline($asOf),
                'body' => $range->body($asOf),
                'is_pinned' => false,
                'published_at' => now(),
                'live_until' => $range->liveUntil(),
            ]);
        });

        $this->notify($announcement);

        return $announcement;
    }

    /**
     * Bring an existing notice back in line with its closure.
     *
     * Called on every run and whenever an admin edits or removes a holiday, so
     * a notice for dates that moved is rewritten and a notice for a closure
     * that no longer exists comes down. Nobody is notified a second time: the
     * bell has already rung, and ringing it again for a correction would read
     * as a new closure.
     */
    public function sync(Announcement $announcement, ?HolidayRange $range): Announcement
    {
        if ($range === null) {
            $this->retract($announcement);

            return $announcement;
        }

        $announcement->fill([
            'holiday_id' => $range->first()->id,
            'title' => $range->headline($announcement->published_at),
            'body' => $range->body($announcement->published_at),
            'live_until' => $range->liveUntil(),
        ]);

        if ($announcement->isDirty()) {
            $announcement->save();
        }

        return $announcement;
    }

    /**
     * Take a notice down.
     *
     * Soft-deleted, the same way a holiday is, but for the opposite reason to
     * the register: a settled attendance row is history and stays, while this
     * notice is a claim about a closure that is no longer going to happen, and
     * leaving it up would be telling students the academy is shut when it is
     * not. Soft keeps the audit trail without keeping the claim. Bells already
     * delivered are not recalled — a notification cannot be unsent — but the
     * notice they point at is gone from the feed and the ticker.
     */
    public function retract(Announcement $announcement): void
    {
        $announcement->delete();
    }

    /**
     * Reconcile whatever notice exists for this holiday's closure.
     *
     * Takes the holiday as it stands now, including a soft-deleted one, so an
     * admin removing or moving a date sees the notice follow immediately
     * rather than at the next scheduled run.
     */
    public function reconcile(Holiday $holiday): void
    {
        $announcement = $this->announcementFor($holiday);

        if ($announcement === null) {
            return;
        }

        // A soft-deleted holiday is not in any range, so this resolves to null
        // and the notice comes down.
        $this->sync($announcement, $holiday->trashed() ? null : HolidayRange::containing($holiday));
    }

    /**
     * Reconcile every notice whose closure falls in a window.
     *
     * Driven from the notices rather than the holidays, because the case that
     * matters most is a holiday that no longer exists — there is nothing left
     * to iterate on that side. An edit can also break one closure into two or
     * join two into one, and the notice stays attached to whichever row was
     * first when it was posted, which may no longer be first; reading the
     * whole window catches both the notice to rewrite and the one to withdraw.
     *
     * @return Collection<int, array{announcement: Announcement, action: string}>
     */
    public function reconcileAround(mixed $from, mixed $to): Collection
    {
        $window = [
            Carbon::parse($from)->copy()->subMonth(),
            Carbon::parse($to)->copy()->addMonth(),
        ];

        $ranges = HolidayRange::between(...$window)
            ->keyBy(fn (HolidayRange $range) => $range->first()->id);

        return Announcement::query()
            ->whereNotNull('holiday_id')
            // withTrashed: a removed holiday is exactly the one whose notice
            // has to come down, and its row is soft-deleted, not gone.
            ->whereHas('holiday', fn ($query) => $query->withTrashed()->betweenDates(...$window))
            ->with(['holiday' => fn ($query) => $query->withTrashed()])
            ->get()
            ->map(function (Announcement $announcement) use ($ranges) {
                $range = $ranges->get($announcement->holiday_id)
                    ?? $this->reanchor($announcement, $ranges);

                $before = [
                    $announcement->title,
                    $announcement->live_until?->toDateTimeString(),
                    $announcement->holiday_id,
                ];

                $this->sync($announcement, $range);

                $action = match (true) {
                    $range === null => 'withdrew',
                    $before !== [
                        $announcement->title,
                        $announcement->live_until?->toDateTimeString(),
                        $announcement->holiday_id,
                    ] => 'updated',
                    default => 'unchanged',
                };

                return ['announcement' => $announcement, 'action' => $action];
            })
            ->values();
    }

    /**
     * Move a notice onto what is left of its closure.
     *
     * A notice is anchored to the first day of the range, and removing exactly
     * that day — one day trimmed off the front of a three-day Eid — would
     * otherwise withdraw a notice that is still true, only shorter, and leave
     * the surviving days unannounced until the next run. Re-anchoring rewrites
     * it in place instead: no gap, and no second bell for a closure everybody
     * has already been told about.
     *
     * A closure removed outright matches nothing here and is withdrawn, which
     * is the point.
     *
     * @param  Collection<int, HolidayRange>  $ranges
     */
    protected function reanchor(Announcement $announcement, Collection $ranges): ?HolidayRange
    {
        $anchor = $announcement->holiday;

        if ($anchor === null) {
            return null;
        }

        $was = $anchor->date->copy()->startOfDay();

        // Same name, and touching where the anchor day was: a run that starts
        // the day after it, or ended the day before, is the same closure with
        // a day trimmed off. Anything further away is a different closure that
        // happens to share a name.
        $surviving = $ranges->first(fn (HolidayRange $range) => $range->name() === $anchor->name
            && $range->start()->lessThanOrEqualTo($was->copy()->addDay())
            && $range->end()->greaterThanOrEqualTo($was->copy()->subDay()));

        if ($surviving !== null) {
            $announcement->holiday_id = $surviving->first()->id;
        }

        return $surviving;
    }

    /** The live notice for this holiday's closure, if one was posted. */
    public function announcementFor(Holiday $holiday): ?Announcement
    {
        return Announcement::where('holiday_id', $holiday->id)->first();
    }

    /**
     * Who a holiday notice is posted as.
     *
     * A staff account, because Announcement::display() reads the author's role
     * to decide between the top-bar ticker and a per-course popup, and a
     * closure belongs on the ticker. Super admin first, then any admin.
     */
    public function author(): ?User
    {
        return User::role('super-admin')->where('is_active', true)->orderBy('id')->first()
            ?? User::role('admin')->where('is_active', true)->orderBy('id')->first();
    }

    /**
     * Ring the bell for everyone the closure affects.
     *
     * Students and instructors both: an instructor turning up to teach on a
     * day the academy is shut is exactly the person this has to reach, and the
     * admin panel has the same notification bell the portal does.
     */
    protected function notify(Announcement $announcement): void
    {
        User::role(['student', 'instructor'])
            ->where('is_active', true)
            ->where('id', '!=', $announcement->author_id)
            ->chunkById(500, function ($recipients) use ($announcement) {
                foreach ($recipients as $recipient) {
                    $recipient->notify(new AnnouncementPublished($announcement));
                }
            });

        $announcement->forceFill(['notified_at' => now()])->save();
    }
}
