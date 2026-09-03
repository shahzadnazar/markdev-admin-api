<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings the RBAC matrix in line with RolePermissionSeeder.
 *
 * The matrix is data, not schema, so it lived only in a seeder — and
 * `php artisan migrate` does not run seeders. Every checkout therefore came up
 * with whatever permissions its database happened to have, which is how one
 * laptop showed Settings to admins and another did not.
 *
 * Running the seeder from a migration means the one command people already run
 * after a pull applies it, and the migrations table records that it has been.
 * The seeder stays the single definition of the matrix; this only invokes it.
 *
 * When the matrix changes again, add another migration that calls it — that is
 * the point at which every database should pick the change up.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RolePermissionSeeder())->run();

        // The seeder writes through the registrar's cache; drop it so the new
        // grants resolve on the very next request instead of after a TTL.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Roles and their grants are the application's baseline, not something
        // this migration introduced — tearing them down would lock everyone out.
    }
};
