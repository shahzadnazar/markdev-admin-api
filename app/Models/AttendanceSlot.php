<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A recurring part of the teaching day, e.g. Morning 9:00 AM – 11:00 AM.
 *
 * Students attend at different times, so lateness cannot be judged against one
 * academy-wide start. A slot carries a time of day and nothing else: it holds
 * no date and repeats every day, and the date it is measured against always
 * comes from the punch being judged. Slots never cross midnight — end_time is
 * always later in the same day than start_time.
 */
class AttendanceSlot extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'days',
        'late_after_minutes',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'is_active' => 'boolean',
            'late_after_minutes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // `name_key` is the normalised name the unique index sits on. It is
        // derived, never posted, so it is kept out of $fillable and written
        // here instead.
        static::saving(function (self $slot): void {
            $slot->name_key = static::normaliseName($slot->name);
        });

        // A soft delete updates the row through the query builder rather than
        // save(), so the hook above never sees it. Clearing the key releases
        // the name: deleting "Morning" has to leave "Morning" creatable again.
        static::deleted(function (self $slot): void {
            if (! $slot->trashed()) {
                return;
            }

            $slot->newQueryWithoutScopes()->whereKey($slot->getKey())->toBase()
                ->update(['name_key' => null]);

            $slot->name_key = null;
            $slot->syncOriginalAttribute('name_key');
        });
    }

    /**
     * The form of a name that decides whether two slots share one.
     *
     * Case and spacing carry no meaning in a slot name: "Morning", "morning"
     * and "  Morning  " are one slot to an admin reading the list, and so are
     * "Slot 1" and "Slot1". Every space is dropped rather than merely
     * collapsed, because that pair differs by whether a space is there at all,
     * not by how many.
     */
    public static function normaliseName(?string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', (string) $name));
    }

    /* --------------------------------- Days -------------------------------- */

    /**
     * ISO-8601 weekday numbers, which is what Carbon's dayOfWeekIso returns.
     *
     * @var array<int, string>
     */
    public const DAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    /**
     * The days this slot runs on, cleaned up and in week order.
     *
     * A slot with no days stored runs every day: that is how every slot
     * behaved before the column existed, so a row that somehow missed the
     * backfill keeps working rather than quietly running on no day at all.
     *
     * @return array<int, int>
     */
    public function dayNumbers(): array
    {
        $days = array_values(array_unique(array_filter(
            array_map('intval', (array) ($this->days ?? [])),
            fn (int $day) => isset(self::DAYS[$day]),
        )));

        sort($days);

        return $days === [] ? array_keys(self::DAYS) : $days;
    }

    /** Whether the slot applies on a given date. */
    public function runsOn(Carbon $day): bool
    {
        return in_array($day->dayOfWeekIso, $this->dayNumbers(), true);
    }

    /** The days this slot and another have in common. @return array<int, int> */
    public function sharedDaysWith(self $other): array
    {
        return array_values(array_intersect($this->dayNumbers(), $other->dayNumbers()));
    }

    /**
     * e.g. "Every day", "Mon–Fri", "Mon, Wed, Fri".
     *
     * Runs of three or more consecutive days read as a range, because that is
     * how a timetable is written and "Mon, Tue, Wed, Thu, Fri" is not.
     */
    public function daysLabel(): string
    {
        return static::labelForDays($this->dayNumbers());
    }

    /** @param  array<int, int>  $days */
    public static function labelForDays(array $days): string
    {
        sort($days);

        if ($days === array_keys(self::DAYS)) {
            return 'Every day';
        }

        $parts = [];
        $run = [];

        foreach ($days as $day) {
            if ($run !== [] && $day !== end($run) + 1) {
                $parts[] = static::runLabel($run);
                $run = [];
            }

            $run[] = $day;
        }

        if ($run !== []) {
            $parts[] = static::runLabel($run);
        }

        return implode(', ', $parts);
    }

    /** @param  array<int, int>  $run */
    protected static function runLabel(array $run): string
    {
        $short = fn (int $day) => mb_substr(self::DAYS[$day], 0, 3);

        return count($run) >= 3
            ? $short($run[0]).'–'.$short(end($run))
            : implode(', ', array_map($short, $run));
    }

    /* ------------------------------ Relations ------------------------------ */

    public function studentProfiles(): HasMany
    {
        return $this->hasMany(StudentProfile::class);
    }

    /* -------------------------------- Scopes ------------------------------- */

    /** Slots offered to new students. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The order the admin arranged them in, earliest slot first on a tie. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('start_time')->orderBy('id');
    }

    /* -------------------------------- Times -------------------------------- */

    /**
     * The slot's start on a given day.
     *
     * The date comes from the caller — a punch timestamp in practice — and the
     * time from the slot, which is what makes one stored slot apply to every
     * day without anyone entering a date.
     */
    public function startsOn(Carbon $day): Carbon
    {
        return $day->copy()->setTimeFromTimeString($this->start_time);
    }

    public function endsOn(Carbon $day): Carbon
    {
        return $day->copy()->setTimeFromTimeString($this->end_time);
    }

    /** The moment arrivals in this slot start counting as late, on a given day. */
    public function lateThresholdOn(Carbon $day): Carbon
    {
        return $this->startsOn($day)->addMinutes($this->late_after_minutes);
    }

    /* ------------------------------- Display ------------------------------- */

    /** Times are stored 24-hour and always shown 12-hour. */
    public function startLabel(): string
    {
        return Carbon::parse($this->start_time)->format('g:i A');
    }

    public function endLabel(): string
    {
        return Carbon::parse($this->end_time)->format('g:i A');
    }

    public function rangeLabel(): string
    {
        return $this->startLabel().' – '.$this->endLabel();
    }

    /**
     * e.g. "Morning (9:00 AM – 11:00 AM · Mon, Wed, Fri)" — how a slot reads
     * in a dropdown.
     *
     * The days belong here: an admin putting a student on a slot is choosing
     * which days that student is judged on, and a name and an hour alone no
     * longer say what the slot does.
     */
    public function label(): string
    {
        return $this->name.' ('.$this->rangeLabel().' · '.$this->daysLabel().')';
    }
}
