<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialRead extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'lesson_resource_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(LessonResource::class, 'lesson_resource_id');
    }
}
