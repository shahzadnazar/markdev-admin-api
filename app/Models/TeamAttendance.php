<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\LocksAbsences;
use App\Models\Concerns\ScopesToDay;
use App\Support\AttendanceMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attendance row per team member per day.
 *
 * ITS OWN TABLE, sharing the academy's MECHANISMS. LocksAbsences, ScopesToDay
 * and AttendanceMath are the same code, not copies: what differs between the
 * two populations is which people are in them and which numbers judge them,
 * and none of that lives in a trait.
 *
 * The absent lock applies here exactly as it does to the register. A team lead
 * who marked somebody absent cannot unmark them — only a holder of
 * `attendance.correct-absent` can, in writing. That is the case the academy
 * leaked twice, and the reason the rule is on the model rather than in the
 * controllers that happen to write it today.
 */
class TeamAttendance extends Model
{
    use Auditable, LocksAbsences, ScopesToDay;

    protected $table = 'team_attendance_records';

    /**
     * What a marker may choose. Four, not five.
     *
     * `excused` was retired in a02a5a2 and is not coming back: it existed
     * because a dropped table had the word, not because this project wanted it.
     */
    public const STATUSES = ['present', 'late', 'absent', 'leave'];

    /** A day nobody has settled yet. Never counted, never shown as a mark. */
    public const PENDING = 'pending';

    /** A dated closure. Settled, but not attendance — kept out of every count. */
    public const HOLIDAY = 'holiday';

    protected $fillable = [
        'user_id',
        'date',
        'status',
        'remarks',
        'arrived_at',
        'source',
        'marked_by',
        'marked_at',
        'last_updated_by',
        'last_update_reason',
        'last_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'marked_at' => 'datetime',
            'last_updated_at' => 'datetime',
        ];
    }

    /* ------------------------------- Scopes -------------------------------- */

    /** Days that count toward a percentage — not pending, not a holiday. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereIn('status', self::STATUSES);
    }

    /** The counted ones plus holidays: everything with an answer. */
    public function scopeDecided(Builder $query): Builder
    {
        return $query->whereIn('status', [...self::STATUSES, self::HOLIDAY]);
    }

    /* ----------------------------- Relations ------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_updated_by');
    }

    /* ------------------------------- Helpers ------------------------------- */

    public function isHoliday(): bool
    {
        return $this->status === self::HOLIDAY;
    }

    /**
     * Weighted attendance for a set of day counts.
     *
     * The same arithmetic the academy register uses, through the same class.
     * The WEIGHTS are shared too — nobody asked for a second set, and a sixth
     * number for two populations to disagree about is not an improvement.
     *
     * @param  array<string, int>  $counts  status => number of days
     */
    public static function weightedPercent(array $counts): ?float
    {
        return AttendanceMath::weightedPercent($counts);
    }

    public function auditContext(): array
    {
        return ['user_id' => $this->user_id, 'date' => $this->date?->toDateString()];
    }
}
