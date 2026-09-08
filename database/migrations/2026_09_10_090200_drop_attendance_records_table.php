<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The class-attendance table, after everything has moved off it.
 *
 * Last and on its own, so a database that has run the backfill but stops here
 * still holds both copies and loses nothing. `biometric_punches` pointed at it
 * and now points at the register instead — the punch keeps its link to the row
 * it produced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_punches', function (Blueprint $table) {
            $table->foreignId('daily_attendance_record_id')
                ->nullable()
                ->after('status')
                ->constrained('daily_attendance_records')
                ->nullOnDelete();
        });

        // The old link pointed at rows that are about to stop existing, and
        // the ids do not carry across — the register assigned its own. A punch
        // is re-linked by the day it belongs to, which is the fact that
        // survived the move.
        foreach (DB::table('biometric_punches')->whereNotNull('attendance_record_id')->get() as $punch) {
            $day = DB::table('attendance_records')->where('id', $punch->attendance_record_id)->first();

            if ($day === null) {
                continue;
            }

            // Matched as a calendar day rather than by equality on the stored
            // value: `date` is date-cast on both tables and comes back in
            // different shapes on different stores.
            $start = \Illuminate\Support\Carbon::parse((string) $day->date)->startOfDay();

            $registerId = DB::table('daily_attendance_records')
                ->where('user_id', $day->user_id)
                ->where('date', '>=', $start->toDateString())
                ->where('date', '<', $start->copy()->addDay()->toDateString())
                ->value('id');

            if ($registerId !== null) {
                DB::table('biometric_punches')
                    ->where('id', $punch->id)
                    ->update(['daily_attendance_record_id' => $registerId]);
            }
        }

        Schema::table('biometric_punches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_record_id');
        });

        Schema::dropIfExists('attendance_records');
    }

    public function down(): void
    {
        // Recreated as it was, so the backfill migration's own down() has
        // somewhere to put the rows back.
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_title')->nullable();
            $table->date('date')->index();
            $table->enum('status', ['present', 'absent', 'late', 'excused']);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20)->default('manual');
            $table->foreignId('biometric_device_id')->nullable()->constrained('biometric_devices')->nullOnDelete();
            $table->foreignId('last_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('last_update_reason', 500)->nullable();
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'date']);
        });

        Schema::table('biometric_punches', function (Blueprint $table) {
            $table->foreignId('attendance_record_id')
                ->nullable()
                ->after('status')
                ->constrained('attendance_records')
                ->nullOnDelete();
        });

        Schema::table('biometric_punches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('daily_attendance_record_id');
        });
    }
};
