<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMethod extends Model
{
    use Auditable, SoftDeletes;

    /** channel value => [label, transactions.method_type]. */
    public const CHANNELS = [
        'jazzcash' => ['label' => 'JazzCash', 'method_type' => 'wallet'],
        'easypaisa' => ['label' => 'EasyPaisa', 'method_type' => 'wallet'],
        'sadapay' => ['label' => 'SadaPay', 'method_type' => 'wallet'],
        'bank_transfer' => ['label' => 'Bank transfer', 'method_type' => 'bank_transfer'],
        'cash_deposit' => ['label' => 'Cash deposit', 'method_type' => 'cash'],
        'other' => ['label' => 'Other', 'method_type' => 'other'],
    ];

    protected $fillable = [
        'name',
        'channel',
        'account_title',
        'account_number',
        'bank_name',
        'instructions',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class);
    }

    public function channelLabel(): string
    {
        return self::CHANNELS[$this->channel]['label'] ?? $this->channel;
    }

    public function methodType(): string
    {
        return self::CHANNELS[$this->channel]['method_type'] ?? 'other';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Active methods a student can pay a course through: the ones attached
     * to the course, or — when the course has none (or there is no course)
     * — every method not restricted to specific courses.
     */
    public static function availableForCourse(?int $courseId): \Illuminate\Database\Eloquent\Collection
    {
        $ordered = fn (Builder $query) => $query->orderBy('sort_order')->orderBy('name');

        if ($courseId) {
            $scoped = $ordered(static::active()->whereHas('courses', fn (Builder $inner) => $inner->where('courses.id', $courseId)))->get();

            if ($scoped->isNotEmpty()) {
                return $scoped;
            }
        }

        return $ordered(static::active()->whereDoesntHave('courses'))->get();
    }
}
