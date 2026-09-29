<?php

namespace App\Models;

use App\Models\Concerns\ScopesToDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of a team leave application, and where it stands.
 *
 * A row exists from the moment somebody applies, so the monthly balance is one
 * query over this table rather than a reconstruction from date ranges. A
 * declined day keeps its row: without one there is no telling a day that was
 * turned down from a day nobody looked at, and the member is owed that
 * difference.
 */
class TeamLeaveApplicationDay extends Model
{
    use ScopesToDay;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    public const STATUSES = [self::PENDING, self::APPROVED, self::DECLINED];

    /**
     * What a day costs the monthly allowance.
     *
     * A pending day is reserved while it waits; a declined day releases its
     * reservation the moment it is declined.
     */
    public const COUNTED = [self::PENDING, self::APPROVED];

    protected $fillable = ['team_leave_application_id', 'date', 'status'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(TeamLeaveApplication::class, 'team_leave_application_id');
    }
}
