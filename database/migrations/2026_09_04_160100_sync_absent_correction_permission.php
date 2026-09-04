<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grants `attendance.correct-absent` to the roles that may undo an absence.
 *
 * RBAC changes ship as migrations here so nobody has to remember to run a
 * seeder; the seeder is the single description of the matrix and this replays
 * it. Admin and super-admin get the new permission because they inherit every
 * non-role, non-backup permission; manager and instructor do not, which is the
 * point — an instructor cannot talk an absence away.
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
