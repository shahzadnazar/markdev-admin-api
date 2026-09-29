<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three places staff talk, and they are three tables.
 *
 * NOT the student `comments` table, for the same reason team leave got its own
 * in 356a12d: that table is scoped to lessons, carries moderation written for
 * students, and a second population in it means every academy query that
 * forgets to exclude staff shows staff. The SHAPE is borrowed from it — one
 * level of replies, soft deletes, audited edits — and what is genuinely common
 * between the three lives in App\Models\Concerns\IsTeamComment rather than
 * being written out three times.
 *
 * ONE LEVEL OF REPLIES on all three. A reply may not be replied to, the same
 * rule as task splitting and for the same reason: two levels is a screen
 * anyone can read and recursion is a screen nobody can.
 *
 * NO COMMENT IS EVER CLIENT-VISIBLE. There is deliberately no per-comment
 * toggle on any of these tables; see the models for why.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Project discussion — private to the team on that project.
        Schema::create('team_project_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // One level: a reply points at an opener, and an opener has no parent.
            $table->foreignId('parent_id')->nullable()->constrained('team_project_comments')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'parent_id']);
        });

        // 2. The cross-team channel — ONE global space where every team talks.
        Schema::create('team_channel_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('team_channel_messages')->cascadeOnDelete();
            $table->text('body');

            // Posted only by an admin, shown distinctly and pinned above the
            // rest. Everybody else posts an ordinary message in the same place.
            $table->boolean('is_announcement')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['parent_id', 'is_announcement']);
        });

        // 3. Task comments — the conversation stays attached to the work.
        Schema::create('team_task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('team_task_comments')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['task_id', 'parent_id']);
        });

        /*
         * Who was mentioned, recorded when the comment is saved.
         *
         * NOTHING DELIVERS ANYTHING YET — notifications are the next phase. The
         * rows exist now because a mention that lives only inside the comment's
         * text is a mention that phase would have to re-parse and guess at,
         * against a membership list that may have changed since.
         *
         * Polymorphic because the three surfaces are three tables and a mention
         * is the same fact on all of them.
         */
        Schema::create('team_comment_mentions', function (Blueprint $table) {
            $table->id();
            $table->morphs('comment');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['comment_type', 'comment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_comment_mentions');
        Schema::dropIfExists('team_task_comments');
        Schema::dropIfExists('team_channel_messages');
        Schema::dropIfExists('team_project_comments');
    }
};
