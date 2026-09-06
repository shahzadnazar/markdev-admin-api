<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Puts the shipped rules in the table, and keeps them in step on later deploys.
 *
 * Delegates to `rules:sync`, which is the same command to run by hand after a
 * release changes a sentence — a migration only runs once, and the wording
 * lives in a table precisely so it can change. Re-runnable either way: a rule
 * an admin has reworded keeps their wording and only has its "reset to
 * default" text refreshed; an untouched rule takes the new sentence.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The same work `rules:sync` does, so a first install and a later
        // deploy that improves a sentence go through one code path.
        Artisan::call('rules:sync');
    }

    public function down(): void
    {
        // Baseline; the table going empty would leave students no rules at all.
    }
};
