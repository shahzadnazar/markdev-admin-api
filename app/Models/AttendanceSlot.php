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
        'late_after_minutes',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
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
     * "Morning", "morning" and "  Morning  " are the same slot to an admin
     * reading the list, so they are the same name here.
     */
    public static function normaliseName(?string $name): string
    {
        return mb_strtolower(trim((string) $name));
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

    /** e.g. "Morning (9:00 AM – 11:00 AM)" — how a slot reads in a dropdown. */
    public function label(): string
    {
        return $this->name.' ('.$this->rangeLabel().')';
    }
}
