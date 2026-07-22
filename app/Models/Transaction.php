<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Transaction extends Model
{
    use Auditable;

    protected $fillable = [
        'invoice_id',
        'user_id',
        'reference',
        'description',
        'method_type',
        'method_brand',
        'method_last4',
        'amount',
        'currency',
        'status',
        'receipt_path',
        'recorded_by',
        'payer_name',
        'bank_name',
        'reference_no',
        'payment_date',
        'notes',
        'submitted_by_student',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'submitted_by_student' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /* ------------------------------ Relations ------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /* ------------------------------ Accessors ------------------------------ */

    public function getReceiptUrlAttribute(): ?string
    {
        return $this->receipt_path ? Storage::disk('public')->url($this->receipt_path) : null;
    }

    public function reviewer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
