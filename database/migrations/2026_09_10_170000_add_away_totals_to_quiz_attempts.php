<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How often a student left the quiz tab, and for how long in total.
 *
 * Totals, not an event log. An events table would let an instructor see that
 * six switches all fell during one hard question, which is genuinely more
 * useful — but the rows would be written from an untrusted client that can
 * flip focus as often as it likes, so the table would be unbounded by
 * anything the server controls. Two counters cannot be made to grow.
 *
 * `last_away_at` is kept because "six times, and the last one two minutes
 * before submitting" reads differently from "six times in the first minute",
 * and it costs one column rather than a table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            // Defaults, not nullable: an attempt nobody switched away from has
            // left the tab zero times, which is a fact rather than an absence.
            // It also means every attempt taken before this shipped reads as
            // zero, and the instructor view shows nothing for those — which is
            // correct, because nothing was measured.
            $table->unsignedInteger('away_count')->default(0)->after('passed');
            $table->unsignedInteger('away_seconds')->default(0)->after('away_count');
            $table->timestamp('last_away_at')->nullable()->after('away_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn(['away_count', 'away_seconds', 'last_away_at']);
        });
    }
};
