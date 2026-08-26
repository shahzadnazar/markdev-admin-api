<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of each lesson video a student actually played.
 *
 * `segments` holds the merged intervals that were played, which is what
 * separates "watched it all" from "dragged the scrubber to the end": a
 * position alone cannot tell those apart, a union of covered ranges can.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_video_progress', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();

            // Video length as the player reported it; 0 until first playback.
            $table->unsignedInteger('duration_seconds')->default(0);
            // Total of the merged segments — time genuinely played.
            $table->unsignedInteger('watched_seconds')->default(0);
            // Furthest point reached, seeking included. Higher than
            // watched_seconds means the student skipped ahead.
            $table->unsignedInteger('furthest_seconds')->default(0);

            $table->json('segments')->nullable();

            $table->unsignedTinyInteger('coverage_percent')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'lesson_id']);
            $table->index(['lesson_id', 'coverage_percent']);
            $table->index(['course_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_video_progress');
    }
};
