<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeePlan extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'user_id',
        'course_id',
        'title',
        'billing_cycle',
        'installment_months',
        'due_day',
        'fine_per_day',
        'starts_at',
        'currency',
        'total_amount',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'fine_per_day' => 'decimal:2',
            'installment_months' => 'integer',
            'due_day' => 'integer',
            'starts_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /* ------------------------------ Relations ------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
