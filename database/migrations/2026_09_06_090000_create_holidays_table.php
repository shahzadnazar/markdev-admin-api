<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Days the academy is closed that no weekly pattern can express.
 *
 * Weekends recur and live in the `academy_working_days` setting; Eid moves
 * every year and 14 August is one date, so those are rows. One row per date
 * rather than a range with two ends: every question asked of this table is
 * "is this date a holiday?", and a range would turn that into arithmetic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 120);
            $table->timestamps();
            // Soft-deleted so a holiday removed by mistake can be read back
            // out of the audit trail; the unique index above sits on `date`
            // alone, so a deleted row still holds its date. Restoring is a
            // deliberate act, and re-adding the same date is refused with a
            // message rather than silently colliding.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
