<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The class-attendance sheet gets the same correction trail the daily
 * register has had since 459f3cc: who overrode a settled absence, when, and
 * the reason they typed. Only an admin can do it, and now the row says so
 * rather than only the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreignId('last_updated_by')->nullable()->after('recorded_by')->constrained('users')->nullOnDelete();
            $table->string('last_update_reason', 500)->nullable()->after('last_updated_by');
            $table->timestamp('last_updated_at')->nullable()->after('last_update_reason');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_updated_by');
            $table->dropColumn(['last_update_reason', 'last_updated_at']);
        });
    }
};
