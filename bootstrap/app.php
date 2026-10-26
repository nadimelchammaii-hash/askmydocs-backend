<?php

use App\Exceptions\ApiErrorFormatter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA mode: requests from SANCTUM_STATEFUL_DOMAINS get session cookies and CSRF protection.
        $middleware->statefulApi();

        // There is no server-rendered login page. Without this, an unauthenticated request that
        // does not send "Accept: application/json" fails with "Route [login] not defined" (500)
        // instead of a 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->respond(new ApiErrorFormatter);
    })->create();
