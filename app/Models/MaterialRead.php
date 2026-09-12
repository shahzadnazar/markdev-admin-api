<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read receipts from the study-materials feature. HISTORY, not live state.
 *
 * Study materials shipped in 8235edd — a /materials list with read receipts
 * that credited learning minutes and auto-completed resource/article lessons —
 * and was removed two weeks later in 9bed5dd, "remove study materials". That
 * removal took the controller and both routes but left the table, this model,
 * and LessonProgressService::recordMaterialRead behind. The service method has
 * now gone too; nothing reads or writes these rows any more.
 *
 * The TABLE and this model are kept deliberately. Rows here are a record of
 * something a student actually did, not state derived from something else, and
 * they cannot be recomputed once dropped. main is still at 8235edd — the commit
 * that ADDED the feature — so anything deployed from it has these endpoints
 * live and may hold real receipts; this branch cannot see that database. A drop
 * is a separate, deliberate migration to run once someone has looked.
 *
 * Do not resurrect this as a way to track reads. The live mechanism is
 * NoteRead (note_reads) behind POST /notes/{note}/read, and the list this fed
 * came back as the Notes page, which since e4cd5f3 covers both lesson-level
 * and course-level resources — strictly more than /materials ever showed.
 */
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
