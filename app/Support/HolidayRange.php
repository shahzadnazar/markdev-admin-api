<?php

namespace App\Support;

use App\Models\Holiday;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A run of consecutive holiday dates that are one closure.
 *
 * The table holds one row per date — a range typed on the create form is
 * expanded there — so a three-day Eid is three rows and has to be put back
 * together before anyone is told about it, or students get three notices for
 * one closure.
 *
 * A run is identified as **consecutive dates carrying the same name**. That is
 * the only thing the rows themselves record about belonging together, and it
 * is the same signal the create form leaves behind. It gets both cases in the
 * spec right: "Eid ul-Fitr" on the 31st, 1st and 2nd is one closure, while
 * "Kashmir Day" on the 5th and "Founders Day" on the 6th stay two, because
 * their names differ. Two rows that share a name *and* a date boundary are one
 * closure by any reading, so merging them is right even if they were entered
 * separately.
 */
class HolidayRange
{
    /**
     * @param  Collection<int, Holiday>  $days  every row in the run, in date order
     */
    protected function __construct(public readonly Collection $days)
    {
    }

    public function first(): Holiday
    {
        return $this->days->first();
    }

    public function name(): string
    {
        return $this->first()->name;
    }

    public function start(): Carbon
    {
        return $this->first()->date->copy()->startOfDay();
    }

    public function end(): Carbon
    {
        return $this->days->last()->date->copy()->startOfDay();
    }

    public function dayCount(): int
    {
        return $this->days->count();
    }

    public function isSingleDay(): bool
    {
        return $this->dayCount() === 1;
    }

    /** The date a notice for this closure is due, given the lead time. */
    public function announceOn(int $daysBefore): Carbon
    {
        return $this->start()->copy()->subDays($daysBefore);
    }

    /**
     * Group holiday rows in a window into closures.
     *
     * The window is widened by a day at each end before grouping, so a run that
     * starts the day before it or ends the day after is seen whole rather than
     * cut in half and announced as two.
     *
     * @return Collection<int, self>
     */
    public static function between(mixed $from, mixed $to): Collection
    {
        $rows = Holiday::query()
            ->betweenDates(Carbon::parse($from)->startOfDay()->subDay(), Carbon::parse($to)->startOfDay()->addDay())
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return static::group($rows);
    }

    /**
     * @param  Collection<int, Holiday>  $rows  in date order
     * @return Collection<int, self>
     */
    public static function group(Collection $rows): Collection
    {
        $runs = collect();
        $current = collect();

        foreach ($rows as $row) {
            $previous = $current->last();

            // A new run starts when the name changes, or when there is a gap:
            // compared on Y-m-d, never by subtracting date-cast values, and a
            // duplicate date (which the unique index forbids anyway) would
            // count as a gap rather than silently extending the run.
            $continues = $previous !== null
                && $previous->name === $row->name
                && $previous->date->copy()->startOfDay()->addDay()->toDateString() === $row->date->copy()->startOfDay()->toDateString();

            if (! $continues && $current->isNotEmpty()) {
                $runs->push(new self($current));
                $current = collect();
            }

            $current->push($row);
        }

        if ($current->isNotEmpty()) {
            $runs->push(new self($current));
        }

        return $runs;
    }

    /** The run this holiday belongs to, read fresh from the table. */
    public static function containing(Holiday $holiday): ?self
    {
        // Reaching a month either side is more than any run can span — the
        // create form caps a range at 31 days — so the run is always whole.
        return static::between(
            $holiday->date->copy()->subMonth(),
            $holiday->date->copy()->addMonth(),
        )->first(fn (self $range) => $range->days->contains('id', $holiday->id));
    }

    /* ------------------------------- Wording ------------------------------- */

    /**
     * The notice's headline, e.g.
     *   "Eid ul-Fitr — the academy is closed tomorrow, 31 March."
     *   "Eid ul-Fitr — the academy is closed 31 March to 2 April."
     *
     * Asia/Karachi throughout, which is what Carbon gives here.
     */
    public function headline(?Carbon $asOf = null): string
    {
        $today = ($asOf ?? Carbon::now())->copy()->startOfDay();

        if (! $this->isSingleDay()) {
            return sprintf(
                '%s — the academy is closed %s to %s.',
                $this->name(),
                $this->start()->format('j F'),
                $this->end()->format('j F'),
            );
        }

        // "Tomorrow" is only said when it is true. A notice sent late — a
        // holiday entered the day before it starts, or on the day — has to say
        // so rather than repeat a word that was right when it was drafted.
        $when = match (true) {
            $this->start()->isSameDay($today) => 'today, ',
            $this->start()->isSameDay($today->copy()->addDay()) => 'tomorrow, ',
            default => 'on '.$this->start()->format('l').', ',
        };

        return sprintf('%s — the academy is closed %s%s.', $this->name(), $when, $this->start()->format('j F'));
    }

    /** The paragraph under the headline. */
    public function body(?Carbon $asOf = null): string
    {
        $reopens = $this->end()->copy()->addDay();

        $opening = $this->isSingleDay()
            ? sprintf('%s falls on %s.', $this->name(), $this->start()->format('l, j F Y'))
            : sprintf(
                '%s runs from %s to %s — %d days.',
                $this->name(),
                $this->start()->format('l, j F Y'),
                $this->end()->format('l, j F Y'),
                $this->dayCount(),
            );

        return $opening
            .' No classes are held and nobody is marked absent for these days,'
            .' so they do not count against attendance or carry a fine.'
            .' The academy reopens on '.$reopens->format('l, j F Y').'.';
    }

    /** When the notice should stop showing: once the academy is open again. */
    public function liveUntil(): Carbon
    {
        return $this->end()->copy()->addDay()->endOfDay();
    }

    /** Stable across re-groupings, so a moved range updates its own notice. */
    public function key(): string
    {
        return $this->name().'|'.$this->start()->toDateString().'|'.$this->end()->toDateString();
    }
}
