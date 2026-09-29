<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopesToDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one team member's absences cost in one month — a ledger row.
 *
 * Not a bill. There is no invoice behind it and no fee plan; somebody reads it
 * off a screen and an admin marks it settled when it is dealt with. See the
 * migration for why AbsenceFineCharge was not extended.
 *
 * The rules are SNAPSHOTTED into the row. Raising the rate next quarter does
 * not rewrite last quarter's ledger under the person who already paid it.
 */
class TeamAbsenceFine extends Model
{
    use Auditable, ScopesToDay;

    /** The day column these scopes default to; pass another per call. */
    public static function dayColumn(): string
    {
        return 'month';
    }

    protected $fillable = [
        'user_id',
        'month',
        'allowance',
        'absences',
        'chargeable',
        'rate',
        'total',
        'settled_on',
        'settled_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date:Y-m-d',
            'settled_on' => 'date:Y-m-d',
            'allowance' => 'integer',
            'absences' => 'integer',
            'chargeable' => 'integer',
            'rate' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function isSettled(): bool
    {
        return $this->settled_on !== null;
    }
}
