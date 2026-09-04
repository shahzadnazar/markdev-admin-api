<?php

namespace App\Models;

use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A student's leave request for a date range.
 *
 * A reviewer decides each day of the range separately, so `status` here is a
 * rollup for lists and filters and `decisions` is the answer. Nothing is
 * written to the register at review time: the day close reads the approved
 * days when it settles each day, which keeps a future approval from marking a
 * day that has not happened and from overwriting a student who turned up.
 */
class LeaveApplication extends Model
{
    public const STATUSES = ['pending', 'approved', 'partially_approved', 'rejected'];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'user_id',
        'from_date',
        'to_date',
        'reason',
        'status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The per-day verdicts. Empty while the application is still pending. */
    public function decisions(): HasMany
    {
        return $this->hasMany(LeaveApplicationDay::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Record the reviewer's verdict on each day of the range.
     *
     * @param  array<int, string>  $approvedDates  Y-m-d strings the reviewer ticked
     * @return string the rollup status this leaves on the application
     */
    public function recordDecisions(array $approvedDates): string
    {
        $approved = collect($approvedDates)
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->intersect(collect($this->days())->map->toDateString())
            ->unique();

        // The rows already exist — applying created them as pending, which is
        // what reserves the days against the student's allowance. Reviewing
        // settles them in place rather than replacing them.
        $existing = $this->existingDecisions();

        foreach ($this->days() as $day) {
            $date = $day->toDateString();
            $status = $approved->contains($date)
                ? LeaveApplicationDay::APPROVED
                : LeaveApplicationDay::DECLINED;

            if ($row = $existing->get($date)) {
                $row->update(['status' => $status]);

                continue;
            }

            $this->decisions()->create(['date' => $date, 'status' => $status]);
        }

        return match (true) {
            $approved->isEmpty() => 'rejected',
            $approved->count() === count($this->days()) => 'approved',
            default => 'partially_approved',
        };
    }

    /**
     * Dates the reviewer approved, as Y-m-d strings.
     *
     * @return array<int, string>
     */
    /**
     * Open one row per day of the range, reserving them against the month's
     * allowance until somebody rules on them.
     */
    public function openDecisions(): void
    {
        $existing = $this->existingDecisions();

        foreach ($this->days() as $day) {
            if ($existing->has($day->toDateString())) {
                continue;
            }

            $this->decisions()->create([
                'date' => $day->toDateString(),
                'status' => LeaveApplicationDay::PENDING,
            ]);
        }
    }

    /**
     * This application's day rows, keyed by Y-m-d.
     *
     * Matched in PHP rather than with a where on `date`: it is a date-cast
     * column, so the stored value comes back as a full datetime on SQLite and
     * an equality against "2027-03-02" finds nothing — which would quietly
     * make a second row for a day that already had one.
     *
     * @return \Illuminate\Support\Collection<string, LeaveApplicationDay>
     */
    protected function existingDecisions(): \Illuminate\Support\Collection
    {
        return $this->decisions()->get()
            ->keyBy(fn (LeaveApplicationDay $day) => Carbon::parse($day->date)->toDateString());
    }

    public function approvedDates(): array
    {
        return $this->decisions()
            ->where('status', LeaveApplicationDay::APPROVED)
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->all();
    }

    /** @return array<int, Carbon> every date in [from_date, to_date] inclusive */
    public function days(): array
    {
        return collect(CarbonPeriod::create($this->from_date, $this->to_date))
            ->map(fn ($day) => Carbon::instance($day))
            ->all();
    }
}
