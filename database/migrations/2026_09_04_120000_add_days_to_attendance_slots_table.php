<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which days of the week a slot runs on.
 *
 * Slots used to apply to every day there was, and the list said so in fixed
 * text. Existing rows are backfilled with the whole week so nothing about a
 * student's lateness changes on the day this ships; an admin narrows a slot
 * from there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_slots', function (Blueprint $table) {
            // ISO-8601 weekday numbers, 1 = Monday through 7 = Sunday.
            $table->json('days')->nullable()->after('end_time');
        });

        DB::table('attendance_slots')->update(['days' => json_encode([1, 2, 3, 4, 5, 6, 7])]);
    }

    public function down(): void
    {
        Schema::table('attendance_slots', function (Blueprint $table) {
            $table->dropColumn('days');
        });
    }
};
