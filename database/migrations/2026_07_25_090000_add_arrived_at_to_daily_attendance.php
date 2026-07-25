<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_attendance_records', function (Blueprint $table) {
            // Actual arrival time: filled automatically from biometric punches,
            // optionally typed by staff when marking manually.
            $table->time('arrived_at')->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('daily_attendance_records', function (Blueprint $table) {
            $table->dropColumn('arrived_at');
        });
    }
};
