<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CalendarEvent extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'course_id',
        'title',
        'type',
        'starts_at',
        'ends_at',
        'action_url',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /* ------------------------------ Relations ------------------------------ */

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
