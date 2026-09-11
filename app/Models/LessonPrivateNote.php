<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's private notes on one lesson.
 *
 * Distinct from Note, which is an instructor's uploaded handout. See the
 * migration for who can read this and why nothing in the admin panel does.
 */
class LessonPrivateNote extends Model
{
    protected $fillable = [
        'lesson_id',
        'user_id',
        'body',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
