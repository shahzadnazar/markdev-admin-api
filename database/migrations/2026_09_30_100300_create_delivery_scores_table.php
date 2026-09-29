<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cached delivery score, for LIST screens only.
 *
 * Exactly the role enrollments.progress_percent plays for course progress, and
 * for the same reason: a team page listing twelve members would otherwise be
 * twelve live calculations. Any screen about ONE person computes live and never
 * reads this.
 *
 * ON THE EVENT, NOT ON A SCHEDULE. Refreshed when a stint closes, when a task
 * status changes, on a reassignment and when the two settings are saved — the
 * four things that can move a figure — plus `delivery:recache` for catching up
 * rows written before any of this existed.
 *
 * THE COUNTS ARE STORED BESIDE THE PERCENTAGE on purpose. The score is never
 * rendered alone; a list screen that had only the number would have to go back
 * to the database for the context that makes it readable, and the component
 * that renders it refuses to draw a bare percentage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Null means "not enough finished work to say" — which is a
            // different statement from 0, and has to stay different.
            $table->unsignedTinyInteger('percent')->nullable();

            $table->unsignedInteger('stints_completed')->default(0);
            $table->unsignedInteger('late_count')->default(0);
            $table->unsignedInteger('days_over')->default(0);
            $table->unsignedInteger('blocked_days')->default(0);

            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_scores');
    }
};
