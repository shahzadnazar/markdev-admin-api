<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student's own notebook for one lesson. Nobody else's business.
 *
 * NOT the `notes` table, which despite its name is instructor file uploads
 * with a course_id, an instructor_id and a file_path — a handout, not a
 * notebook. Reusing it would have put a student's private writing in the same
 * table an instructor's published material is served from, one bad query away
 * from being handed to the wrong person.
 *
 * WHO CAN SEE THESE: the student who wrote them, and nobody else — not other
 * students, not the lesson's instructor, not an admin. That is enforced by
 * every query being scoped to the authenticated user and by there being no
 * admin route, resource or view that reads this table at all. It is a
 * deliberate absence, not an oversight.
 *
 * What it is NOT: encryption. Anyone with database access can read this table,
 * exactly as they can read any other. The guarantee is about the application,
 * not the disk.
 *
 * One row per student per lesson: the note is a page in a notebook, not a
 * stream of entries, so the endpoint is an upsert and the unique index is what
 * makes that safe under a double-submit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_private_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->unique(['lesson_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_private_notes');
    }
};
