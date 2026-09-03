<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops the stored site-wide timezone. The admin form that wrote this key is
 * gone; leaving the row behind would keep a value nothing reads, which is how
 * it came to look configurable in the first place.
 *
 * Only the one row goes — the settings table holds the site name, the billing
 * numbers and the attendance keys alongside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'timezone')->delete();
    }

    public function down(): void
    {
        // Nothing to restore: the value is gone and nothing reads the key.
    }
};
