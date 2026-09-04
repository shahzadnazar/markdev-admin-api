<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of a leave application, and what the reviewer decided about it.
 *
 * A declined day gets a row too. Without one there is no way to tell a day
 * that was turned down from a day nobody looked at, and the student is owed
 * that difference.
 */
class LeaveApplicationDay extends Model
{
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    public const STATUSES = [self::APPROVED, self::DECLINED];

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
