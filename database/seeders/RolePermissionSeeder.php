<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Database-driven RBAC per the MarkDev role hierarchy. Nothing is hardcoded
 * in the application: gates check these permission names only.
 */
class RolePermissionSeeder extends Seeder
{
    /** @var array<string, string[]> module => actions */
    protected array $matrix = [
        'dashboard' => ['view'],
        'users' => ['view', 'create', 'update', 'delete', 'restore'],
        'students' => ['view', 'create', 'update', 'delete'],
        'roles' => ['view', 'create', 'update', 'delete'],
        'categories' => ['view', 'create', 'update', 'delete'],
        'courses' => ['view', 'create', 'update', 'delete', 'restore'],
        'lessons' => ['view', 'create', 'update', 'delete'],
        'enrollments' => ['view', 'create', 'update', 'delete'],
        'assignments' => ['view', 'create', 'update', 'delete', 'grade'],
        'quizzes' => ['view', 'create', 'update', 'delete'],
        'attendance' => ['view', 'manage', 'daily'],
        'devices' => ['view', 'manage'],
        'certificates' => ['view', 'issue', 'delete'],
        'announcements' => ['view', 'create', 'update', 'delete'],
        'media' => ['view', 'upload', 'delete'],
        'billing' => ['view', 'manage'],
        'help' => ['view', 'manage'],
        'reports' => ['view', 'export'],
        'audit-logs' => ['view', 'export'],
        'settings' => ['view', 'update'],
        'backups' => ['view', 'run'],
        'notifications' => ['send'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = [];
        foreach ($this->matrix as $module => $actions) {
            foreach ($actions as $action) {
                $name = "{$module}.{$action}";
                Permission::findOrCreate($name, 'web');
                $all[] = $name;
            }
        }

        // Reset the registrar cache so the fresh permissions resolve by name.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Super Admin owns everything (also enforced by Gate::before).
        Role::findOrCreate('super-admin', 'web')->syncPermissions($all);

        // Admin: everything except role management, system settings and backups.
        Role::findOrCreate('admin', 'web')->syncPermissions(
            collect($all)->reject(
                fn (string $name) => str_starts_with($name, 'roles.')
                    || str_starts_with($name, 'settings.')
                    || str_starts_with($name, 'backups.')
            )->values()->all()
        );

        Role::findOrCreate('manager', 'web')->syncPermissions([
            'dashboard.view',
            'users.view',
            'users.create',
            'users.update',
            'students.view',
            'students.create',
            'students.update',
            'categories.view',
            'courses.view',
            'courses.create',
            'courses.update',
            'lessons.view',
            'enrollments.view',
            'enrollments.create',
            'enrollments.update',
            'enrollments.delete',
            'assignments.view',
            'quizzes.view',
            'attendance.view',
            'attendance.manage',
            'attendance.daily',
            'devices.view',
            'devices.manage',
            'announcements.view',
            'announcements.create',
            'announcements.update',
            'reports.view',
            'reports.export',
        ]);

        Role::findOrCreate('instructor', 'web')->syncPermissions([
            'dashboard.view',
            'categories.view',
            'courses.view',
            'courses.create',
            'courses.update',
            'lessons.view',
            'lessons.create',
            'lessons.update',
            'lessons.delete',
            'enrollments.view',
            'assignments.view',
            'assignments.create',
            'assignments.update',
            'assignments.delete',
            'assignments.grade',
            'quizzes.view',
            'quizzes.create',
            'quizzes.update',
            'quizzes.delete',
            'attendance.view',
            'attendance.manage',
            'announcements.view',
            'announcements.create',
            'announcements.update',
            'announcements.delete',
            'media.view',
            'media.upload',
        ]);

        // Students act through the API with ownership checks; no admin panel access.
        Role::findOrCreate('student', 'web');
    }
}
