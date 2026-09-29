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
        'notes' => ['view', 'create', 'update', 'delete'],
        'enrollments' => ['view', 'create', 'update', 'delete'],
        'assignments' => ['view', 'create', 'update', 'delete', 'grade'],
        'quizzes' => ['view', 'create', 'update', 'delete'],
        // `daily` is the unscoped register and leave list; `daily.own-category`
        // is the same screens limited to the categories an instructor teaches
        // in. Two permissions rather than one, because a single name that
        // means "everything" for a manager and "my field" for an instructor
        // would make every future can('attendance.daily') check silently wrong
        // for one of them.
        'attendance' => ['view', 'manage', 'daily', 'daily.own-category', 'correct-absent'],
        'devices' => ['view', 'manage'],
        'certificates' => ['view', 'issue', 'delete'],
        'announcements' => ['view', 'create', 'update', 'delete'],
        'billing' => ['view', 'manage'],
        'help' => ['view', 'manage'],
        'reports' => ['view', 'export'],
        'audit-logs' => ['view', 'export'],
        'settings' => ['view', 'update'],
        'backups' => ['view', 'run'],
        'notifications' => ['send'],

        /*
         * THE TEAM PORTAL. Project management for MarkDev's own staff, which
         * shares this login and nothing else with the academy.
         *
         * These modules are deliberately their own names rather than actions
         * bolted onto an academy module: a team lead is not a junior manager
         * and an instructor is not a junior team member. The two jobs happen
         * to be done by people with one account each, and a permission that
         * spanned both would make every future check ambiguous about which
         * job it was asking about.
         *
         * `task-statuses.manage` and `project-statuses.manage` gate the two
         * status lists under Settings. Two permissions because there are two
         * tables with two independent behaviour sets, and one name covering
         * both would mean an academy that wanted to hand out project statuses
         * could not do it without handing out task statuses as well.
         */
        'teams' => ['view', 'create', 'update', 'delete'],
        'projects' => ['view', 'create', 'update', 'delete'],
        'tasks' => ['view', 'create', 'update', 'delete'],
        'clients' => ['view', 'create', 'update', 'delete'],
        'task-statuses' => ['manage'],
        'project-statuses' => ['manage'],
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

        // Admin: everything except role management and backups. Settings is
        // included because the attendance marking mode lives there and admins
        // are the ones who switch it.
        Role::findOrCreate('admin', 'web')->syncPermissions(
            collect($all)->reject(
                fn(string $name) => str_starts_with($name, 'roles.')
                    || str_starts_with($name, 'backups.')
            )->values()->all()
        );

        // Managers run the academy, not client work: no team, project, task or
        // client permission belongs here. The list is explicit rather than
        // derived, so a team module added above never lands in it by accident.
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

        // Academy only, for the same reason. An instructor who also does client
        // work is given the `team` role on top of this one; they are never
        // merged, because then neither list could be changed on its own.
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
            // The leave list and the daily register, limited to their own
            // categories. The controllers enforce that on the query; this
            // only decides who reaches the screens at all.
            'attendance.daily.own-category',
            'announcements.view',
            'announcements.create',
            'announcements.update',
            'announcements.delete',
            'notes.view',
            'notes.create',
            'notes.update',
            'notes.delete',
        ]);

        // Students act through the API with ownership checks; no admin panel access.
        Role::findOrCreate('student', 'web');

        /*
         * ------------------------------ Team portal ------------------------
         *
         * NOT academy roles. Nothing below grants a single academy permission,
         * and nothing above grants a single team one. Someone who teaches and
         * also runs client projects holds two roles and gets the union; that is
         * the only way the two sets ever meet, and it is a decision made per
         * person rather than baked into a role.
         *
         * Enforced by TeamRoleSeparationTest in both directions.
         */

        // A team lead runs one team's work: they see the team and the projects
        // it is on, and own its task list. They do not create projects — that
        // is an admin decision about what MarkDev has agreed to deliver — and
        // they cannot see clients.
        Role::findOrCreate('team-lead', 'web')->syncPermissions([
            'teams.view',
            'projects.view',
            'tasks.view',
            'tasks.create',
            'tasks.update',
            'tasks.delete',
        ]);

        // A team member works a task list. `tasks.update` is how they move a
        // task along; the narrowing to their OWN tasks is a query concern in
        // the phase that builds those screens, not a second permission —
        // there is no unscoped task screen for this role to be confused with.
        Role::findOrCreate('team', 'web')->syncPermissions([
            'projects.view',
            'tasks.view',
            'tasks.update',
        ]);

        // Clients get their own portal, not this one. The role exists so a
        // client account can be recognised and so nothing has to invent one
        // later; it holds no admin-panel permission at all, like `student`.
        Role::findOrCreate('client', 'web');
    }
}
