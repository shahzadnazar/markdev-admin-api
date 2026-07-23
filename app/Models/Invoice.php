<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Invoice extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'fee_plan_id',
        'user_id',
        'number',
        'sequence_no',
        'title',
        'amount',
        'fine_amount',
        'fine_days',
        'currency',
        'status',
        'issued_at',
        'activates_at',
        'due_at',
        'paid_at',
        'grace_notified_at',
        'file_path',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fine_amount' => 'decimal:2',
            'fine_days' => 'integer',
            'sequence_no' => 'integer',
            'activates_at' => 'date',
            'grace_notified_at' => 'datetime',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /* ------------------------------ Relations ------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function feePlan(): BelongsTo
    {
        return $this->belongsTo(FeePlan::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /* ------------------------------ Accessors ------------------------------ */

    public function getDownloadUrlAttribute(): ?string
    {
        return $this->file_path ? Storage::disk('public')->url($this->file_path) : null;
    }

    /** The most recent student fee submission against this invoice. */
    public function latestSubmission(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Transaction::class)
            ->ofMany(['id' => 'max'], fn ($query) => $query->where('submitted_by_student', true));
    }

    /* --------------------------- Installment helpers ------------------------ */

    /** Installment amount plus any accrued defaulter fine. */
    public function getPayableTotalAttribute(): float
    {
        return round((float) $this->amount + (float) $this->fine_amount, 2);
    }

    /** Due date has passed but the grace window hasn't ended yet. */
    public function isInGrace(int $graceDays): bool
    {
        return $this->status !== 'paid'
            && $this->due_at !== null
            && $this->due_at->isPast()
            && ! $this->due_at->copy()->addDays($graceDays)->isPast();
    }

    public function daysOverdue(): int
    {
        if ($this->due_at === null || ! $this->due_at->isPast() || $this->status === 'paid') {
            return 0;
        }

        return (int) $this->due_at->copy()->startOfDay()->diffInDays(now()->startOfDay());
    }
}
