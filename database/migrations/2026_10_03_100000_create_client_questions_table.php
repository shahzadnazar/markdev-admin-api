<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one thing a client may write: a question about their own project.
 *
 * ## ONE QUESTION, ONE ANSWER — and that is why this is not a comment table
 *
 * Phase 5 built three comment surfaces and the reasoning for each was that it
 * had its own population and its own visibility rule. This is not a fourth: it
 * is a question with an answer beside it, which is why the answer is COLUMNS on
 * this row rather than rows in a thread. A thread here would grow into a
 * client-visible conversation, and the moment it did, every rule phase 5 wrote
 * down — no comment is ever client-visible, there is no per-comment toggle —
 * would have a place to leak through.
 *
 * The lead's DISCUSSION of a question happens in the ordinary project
 * discussion, which already exists and which a client cannot see. So: the client
 * asks, the team talks among themselves where they always have, and one answer
 * comes back.
 *
 * ## `status` is not an enum
 *
 * open | answered | closed, fixed in PHP on ClientQuestion::STATUSES like every
 * other behaviour set in this codebase — TaskAssignment::OUTCOMES,
 * TaskStatus::BEHAVIOURS. The code is what branches on them, so the code is
 * where they live; a database enum would need a migration to add a state and
 * would still not stop a typo in PHP.
 *
 * ## Who may answer is NOT a column
 *
 * The team-lead of the project's team, an admin or a super-admin — see
 * ClientQuestion::mayBeAnsweredBy. Derived from the project's team on every
 * call rather than stamped here, because a team's lead changes and a stamped
 * answer-authority would keep pointing at whoever held the job when the
 * question was asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_questions', function (Blueprint $table) {
            $table->id();

            // Cascade: a deleted project's questions are about nothing. Projects
            // are soft-deleted, so this only fires on a force delete.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // The client's LOGIN, not the client record. The row records who
            // typed it, and a client record may be relinked to another login.
            $table->foreignId('asked_by')->constrained('users')->cascadeOnDelete();

            $table->text('body');

            // Default 'open' here as well as in the model: a row inserted by a
            // seeder or a console command past the model's $attributes should
            // still be in a state the screens can read.
            $table->string('status', 20)->default('open');

            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('answer_body')->nullable();
            $table->timestamp('answered_at')->nullable();

            $table->timestamps();

            // The two reads: a client's project page, and a lead's. Both ask for
            // one project's questions with the open ones first.
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_questions');
    }
};
