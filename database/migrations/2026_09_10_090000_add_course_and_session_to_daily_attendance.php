<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two facts the class-attendance sheet held that the register did not.
 *
 * Everything else `attendance_records` carried, the register already has and
 * in a richer form: source, arrived_at, and the correction trail. These two
 * columns are what makes it the single table.
 *
 * Both nullable: a day the student attended the academy without a lecture —
 * or before this column existed — has no course, and saying so with NULL is
 * better than inventing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_attendance_records', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('session_title')->nullable()->after('course_id');
        });
    }

    public function down(): void
    {
        Schema::table('daily_attendance_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_id');
            $table->dropColumn('session_title');
        });
    }
};
