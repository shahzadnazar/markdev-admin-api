<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds the team portal's dashboard and reports permissions.
 *
 * RBAC changes ship as migrations so nobody has to remember to run a seeder;
 * the seeder is the single description of the matrix and this replays it, the
 * same shape as 2026_09_28_100000_sync_team_portal_permissions.
 *
 * Super-admin and admin pick the three new permissions up with everything else,
 * because admin is derived by rejecting `roles.` and `backups.` from the full
 * set rather than by listing what it has. Manager and instructor are listed
 * explicitly in the seeder and gain NOTHING — `team-dashboard` and
 * `team-reports` are team modules, and the academy's own `dashboard` and
 * `reports` are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RolePermissionSeeder)->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Baseline; tearing the matrix down would lock everyone out.
    }
};
