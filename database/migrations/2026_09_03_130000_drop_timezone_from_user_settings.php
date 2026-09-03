<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The academy runs in Asia/Karachi and nowhere else, so a per-student
 * timezone was never read by anything — the column only ever held the
 * value a settings screen wrote back to it.
 *
 * Timestamps are untouched: they carry no zone data and always meant local
 * wall-clock time, so nothing here shifts an existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->string('timezone')->default('UTC')->after('user_id');
        });
    }
};
