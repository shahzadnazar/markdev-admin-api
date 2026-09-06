<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an announcement say which holiday it is about, and how long to stay up.
 *
 * `holiday_id` is what makes the announcer idempotent: it points at the first
 * day of the range, so a second run on the same morning finds the notice it
 * already sent instead of posting another, and a holiday that is later moved
 * or removed can be traced back to the notice that has to change with it.
 *
 * `live_until` exists because a holiday notice has a natural expiry that the
 * fixed 24-hour window does not fit — a notice for a three-day Eid should
 * still be on the ticker on the second day, and gone once the academy reopens.
 * Null everywhere else, which keeps every existing announcement on the 24-hour
 * rule it has always had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->foreignId('holiday_id')->nullable()->after('course_id')
                ->constrained()->nullOnDelete();
            $table->timestamp('live_until')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('holiday_id');
            $table->dropColumn('live_until');
        });
    }
};
