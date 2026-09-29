<?php

namespace App\Models\Concerns;

use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Per-day partial approval: one row per expected day, one verdict each.
 *
 * EXTRACTED FROM LeaveApplication, not copied, when the team portal needed the
 * same flow over its own tables. A reviewer decides each day of a range
 * separately, so the application's `status` is a rollup for lists and the day
 * rows are the answer.
 *
 * The host supplies two things and nothing else:
 *
 *   decisions()    the day rows
 *   days()         which days of the range are actually expected — a student
 *                  follows their attendance slot, a team member follows the
 *                  academy's working week. That is the ONLY difference between
 *                  the two populations, which is why it is the only thing left
 *                  for them to say.
 *
 * Plus the three status constants on the day model, read through dayStatus().
 */
trait DecidesLeavePerDay
{
    /** @var array<int, Carbon>|null memo for days(), per instance */
    protected ?array $expectedDays = null;

    /** The day model these decisions are rows of. */
    abstract public static function dayModel(): string;

    /**
     * Open one row per day of the range, reserving them against the month's
     * allowance until somebody rules on them.
     *
     * A pending day is reserved while it waits: without that, several requests
     * could be filed at once and only blow the allowance once they were all
     * approved, which is too late to refuse any of them.
     */
    public function openDecisions(): void
    {
        $existing = $this->existingDecisions();
        $dayModel = static::dayModel();

        foreach ($this->days() as $day) {
            if ($existing->has($day->toDateString())) {
                continue;
            }

            $this->decisions()->create([
                'date' => $day->toDateString(),
                'status' => $dayModel::PENDING,
            ]);
        }
    }

    /**
     * Record the reviewer's verdict on each day of the range.
     *
     * @param  array<int, string>  $approvedDates  Y-m-d strings the reviewer ticked
     * @return string the rollup status this leaves on the application
     */
    public function recordDecisions(array $approvedDates): string
    {
        $dayModel = static::dayModel();

        $approved = collect($approvedDates)
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->intersect(collect($this->days())->map->toDateString())
            ->unique();

        // The rows already exist — applying created them as pending, which is
        // what reserves the days. Reviewing settles them in place.
        $existing = $this->existingDecisions();

        foreach ($this->days() as $day) {
            $date = $day->toDateString();
            $status = $approved->contains($date) ? $dayModel::APPROVED : $dayModel::DECLINED;

            if ($row = $existing->get($date)) {
                $row->update(['status' => $status]);

                continue;
            }

            $this->decisions()->create(['date' => $date, 'status' => $status]);
        }

        return match (true) {
            // Every day of the range fell on a weekend or a holiday, so there
            // was nothing to refuse. Calling that a rejection would tell
            // somebody they were turned down for days off.
            $this->days() === [] => 'approved',
            $approved->isEmpty() => 'rejected',
            $approved->count() === count($this->days()) => 'approved',
            default => 'partially_approved',
        };
    }

    /**
     * This application's day rows, keyed by Y-m-d.
     *
     * Matched in PHP rather than with a where on `date`: it is a date-cast
     * column, so the stored value comes back as a full datetime on SQLite and
     * an equality against "2027-03-02" finds nothing — which would quietly
     * make a second row for a day that already had one.
     *
     * @return Collection<string, Model>
     */
    protected function existingDecisions(): Collection
    {
        return $this->decisions()->get()
            ->keyBy(fn ($day) => Carbon::parse($day->date)->toDateString());
    }

    /** @return array<int, string> the dates the reviewer approved */
    public function approvedDates(): array
    {
        $dayModel = static::dayModel();

        return $this->decisions()
            ->where('status', $dayModel::APPROVED)
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->all();
    }

    /**
     * Every date in the range, working day or not.
     *
     * Only for showing somebody what they asked for; nothing counts from here.
     *
     * @return array<int, Carbon>
     */
    public function allDates(): array
    {
        return collect(CarbonPeriod::create($this->from_date, $this->to_date))
            ->map(fn ($day) => Carbon::instance($day)->startOfDay())
            ->all();
    }
}
