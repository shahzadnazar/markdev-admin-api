<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one student's absences cost in one calendar month.
 *
 * A row appears when the month is charged, and never twice for the same month
 * — that uniqueness is what makes the charge command safe to re-run. `amount`
 * records what actually went on an invoice and is not rewritten afterwards: a
 * correction credits the difference back instead, because an issued invoice
 * must not change under the student.
 */
class AbsenceFineCharge extends Model
{
    protected $fillable = [
        'user_id',
        'month',
        'absences',
        'chargeable',
        'fine_per_absent',
        'amount',
        'credited_amount',
        'pending_credit',
        'invoice_id',
        'charged_at',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'absences' => 'integer',
            'chargeable' => 'integer',
            'fine_per_absent' => 'decimal:2',
            'amount' => 'decimal:2',
            'credited_amount' => 'decimal:2',
            'pending_credit' => 'decimal:2',
            'charged_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** What the student is still net-charged for this month. */
    public function netCharged(): float
    {
        return round((float) $this->amount - (float) $this->credited_amount - (float) $this->pending_credit, 2);
    }
}
