<?php

use App\Http\Middleware\EnforceMaintenanceMode;
use App\Http\Middleware\EnsureClientPortal;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            // The client portal's door. Not a permission, and not a role name:
            // what entitles somebody is a client record pointing at their login.
            'client' => EnsureClientPortal::class,
            // Scheduled downtime. On the student API and the client portal, and
            // on NEITHER admin group — staff are the ones doing the maintenance,
            // and an admin locked out of the setting that turns this off has a
            // problem no banner can fix.
            'maintenance' => EnforceMaintenanceMode::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
