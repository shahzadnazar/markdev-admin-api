<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds `attendance.daily.own-category` and gives it to instructors.
 *
 * RBAC changes ship as migrations so nobody has to remember to run a seeder;
 * the seeder is the single description of the matrix and this replays it.
 * Admin and super-admin inherit it with everything else, and keep the
 * unscoped `attendance.daily` they already had — so their view does not
 * narrow. Manager keeps `attendance.daily` alone, which is unscoped too.
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
