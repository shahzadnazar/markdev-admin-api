<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A resource can hang off a COURSE as well as a lesson.
 *
 * One table, option (a): `lesson_id` becomes nullable and a nullable
 * `course_id` joins it. The alternative — a second course_resources table —
 * is cleaner to read but would duplicate the kind/url/file discriminator from
 * 589e4db, its validation, its YouTube host matching, its http-only rule, the
 * admin form component and the API shape. Two copies of an invariant is how
 * they drift.
 *
 * The audit that decided it: the only progress-critical query over this table
 * is LessonProgressService, and it reads `$lesson->resources()` — scoped by
 * lesson_id, so course-level rows cannot enter a lesson's completion count —
 * while recordMaterialRead() already returns early when a resource has no
 * lesson. Nothing else queries the table unscoped.
 *
 * The cost is a SECOND invariant beside the file/link one: exactly one of
 * lesson_id and course_id is set. It is enforced in LessonResource::saving()
 * rather than by a CHECK constraint, because the syntax differs across the
 * drivers this runs on and a model guard is one place that behaves the same
 * everywhere. There is a test for a row with both and a row with neither.
 *
 * The table keeps its name. Renaming it would rewrite the FK in
 * material_reads and three migrations for a word, and `lesson_resources` is
 * where a reader already looks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_resources', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable()->after('lesson_id')
                ->constrained()->cascadeOnDelete();
        });

        Schema::table('lesson_resources', function (Blueprint $table) {
            $table->unsignedBigInteger('lesson_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Course-level rows have no lesson to fall back to, so they go rather
        // than being left pointing at nothing.
        DB::table('lesson_resources')->whereNull('lesson_id')->delete();

        Schema::table('lesson_resources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_id');
        });

        Schema::table('lesson_resources', function (Blueprint $table) {
            $table->unsignedBigInteger('lesson_id')->nullable(false)->change();
        });
    }
};
