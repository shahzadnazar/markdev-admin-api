<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $superAdmin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin@markdev.test',
            'headline' => 'Platform Owner',
        ]);
        $superAdmin->assignRole('super-admin');

        $admin = User::factory()->create([
            'name' => 'MarkDev Admin',
            'email' => 'admin@markdev.test',
            'headline' => 'Administrator',
        ]);
        $admin->assignRole('admin');

        $manager = User::factory()->create([
            'name' => 'MarkDev Manager',
            'email' => 'manager@markdev.test',
            'headline' => 'Academic Manager',
        ]);
        $manager->assignRole('manager');

        $this->call(DemoSeeder::class);
    }
}
