<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A quiz's clock becomes seconds PER QUESTION, derived at run time.
 *
 * `time_limit_minutes` was a whole-quiz total, so a quiz that gained a question
 * kept its old clock — and questions are added by QuestionController, which
 * never touches the quiz row, so nothing could have recomputed it. The new
 * column holds the per-question rate and the total is worked out when an
 * attempt starts.
 *
 * Both columns become nullable, meaning "follow the academy default". Existing
 * quizzes are moved to NULL so the new policy — one attempt, 30s a question —
 * actually reaches them, rather than leaving every current quiz pinned to the
 * old numbers forever. An admin who wants a longer final exam sets the
 * per-quiz override, which is what it is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->unsignedInteger('seconds_per_question')->nullable()->after('description');
        });

        Schema::table('quizzes', function (Blueprint $table) {
            // NULL now means "use the academy default". It could not before:
            // the column was NOT NULL DEFAULT 1.
            $table->unsignedInteger('attempts_allowed')->nullable()->default(null)->change();
        });

        // Every existing quiz follows the new academy defaults. Nothing is
        // deleted and no attempt is touched: this row says what a FUTURE
        // attempt gets, and attempts already taken keep their own expires_at
        // and their own score.
        DB::table('quizzes')->update([
            'seconds_per_question' => null,
            'attempts_allowed' => null,
        ]);

        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('time_limit_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->unsignedInteger('time_limit_minutes')->nullable()->after('description');
        });

        // Rebuild a whole-quiz total from the rate and the questions the quiz
        // has RIGHT NOW, which is the best a reversal can do — the old column
        // never knew the question count, which is why it was replaced.
        foreach (DB::table('quizzes')->get(['id', 'seconds_per_question']) as $quiz) {
            $questions = max(1, DB::table('questions')->where('quiz_id', $quiz->id)->count());
            $seconds = ($quiz->seconds_per_question ?? 30) * $questions;

            DB::table('quizzes')->where('id', $quiz->id)->update([
                'time_limit_minutes' => max(1, (int) ceil($seconds / 60)),
            ]);
        }

        DB::table('quizzes')->whereNull('attempts_allowed')->update(['attempts_allowed' => 1]);

        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('seconds_per_question');
        });

        Schema::table('quizzes', function (Blueprint $table) {
            $table->unsignedInteger('attempts_allowed')->default(1)->change();
        });
    }
};
