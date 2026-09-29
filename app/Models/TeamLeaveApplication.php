<?php

namespace App\Models;

use App\Models\Concerns\DecidesLeavePerDay;
use App\Models\Concerns\ScopesToDay;
use App\Support\AcademyCalendar;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A team member's leave request for a date range.
 *
 * The academy's flow over its own table: a reviewer decides each day
 * separately, so `status` is a rollup for lists and `decisions` is the answer.
 * The mechanics are DecidesLeavePerDay, extracted from LeaveApplication rather
 * than copied — the only thing this model says for itself is which days of a
 * range it expects, and that is the one real difference between the two
 * populations.
 *
 * NO SLOTS, so the expected days are simply the academy's working week minus
 * the holidays. A weekend inside a Friday-to-Monday request is not leave from
 * anything and never becomes a row, spends no allowance and is put to nobody.
 */
class TeamLeaveApplication extends Model
{
    use DecidesLeavePerDay, ScopesToDay;

    public const STATUSES = ['pending', 'approved', 'partially_approved', 'rejected'];

    protected $attributes = ['status' => 'pending'];

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
            'from_date' => 'date:Y-m-d',
            'to_date' => 'date:Y-m-d',
            'reviewed_at' => 'datetime',
        ];
    }

    public static function dayColumn(): string
    {
        return 'from_date';
    }

    public static function dayModel(): string
    {
        return TeamLeaveApplicationDay::class;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(TeamLeaveApplicationDay::class)->orderBy('date');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * The working days of this range.
     *
     * Memoised for the life of the instance, not statically: recordDecisions
     * asks three times and each call would otherwise re-read the holidays.
     *
     * @return array<int, Carbon>
     */
    public function days(): array
    {
        if ($this->expectedDays !== null) {
            return $this->expectedDays;
        }

        $holidays = AcademyCalendar::holidayMap($this->from_date, $this->to_date);

        return $this->expectedDays = collect(CarbonPeriod::create($this->from_date, $this->to_date))
            ->map(fn ($day) => Carbon::instance($day)->startOfDay())
            ->filter(fn (Carbon $day) => AcademyCalendar::isWorkingWeekday($day)
                && ! $holidays->has($day->toDateString()))
            ->values()
            ->all();
    }
}
