<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds the team portal's permissions and its three roles.
 *
 * RBAC changes ship as migrations so nobody has to remember to run a seeder;
 * the seeder is the single description of the matrix and this replays it.
 * Super-admin and admin pick the new modules up with everything else, because
 * admin is derived by rejecting `roles.` and `backups.` from the full set
 * rather than by listing what it has.
 *
 * Manager and instructor are listed explicitly in the seeder and gain nothing:
 * team-lead, team and client are separate jobs that happen to share a login
 * with the academy, not a promotion inside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RolePermissionSeeder())->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Baseline; tearing the matrix down would lock everyone out.
    }
};
