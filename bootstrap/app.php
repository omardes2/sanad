<?php

use App\Http\Middleware\EnsureLegacyAdminOrPermission;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Provider webhooks: root path, no middleware group (no CSRF/session).
            Route::group([], __DIR__.'/../routes/webhooks.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Operator-dashboard authorization. Use together with "auth", which
        // must run first so a guest is redirected to login before this checks
        // the admin flag.
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            // Legacy is_admin OR permission — only for pages that pre-date RBAC.
            'permission.legacy' => EnsureLegacyAdminOrPermission::class,
            // Strict RBAC gates (spatie) for pages introduced with/after Phase C0.
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never re-flash a secret into the session on a validation redirect
        // (Phase C3 write-only credential form).
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'secret', 'api_key', 'credential']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
