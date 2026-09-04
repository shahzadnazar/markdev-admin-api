<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of a leave application, and where it stands.
 *
 * A row exists from the moment the student applies, so the monthly balance is
 * one query over this table rather than a reconstruction from date ranges. A
 * declined day keeps its row: without one there is no telling a day that was
 * turned down from a day nobody looked at, and the student is owed that
 * difference.
 */
class LeaveApplicationDay extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    public const STATUSES = [self::PENDING, self::APPROVED, self::DECLINED];

    /**
     * What a day costs the student's monthly allowance.
     *
     * A pending day is reserved while it waits: without that, several requests
     * could be filed at once and only blow the allowance once they were all
     * approved, which is too late to refuse any of them. A declined day
     * releases its reservation the moment it is declined.
     */
    public const COUNTED = [self::PENDING, self::APPROVED];

    protected $fillable = ['leave_application_id', 'date', 'status'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function leaveApplication(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class);
    }
}
