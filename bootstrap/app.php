<?php

use App\Http\Middleware\AuthenticateAccessToken;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\OptionalAccessToken;
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
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind the production TLS-terminating proxy so request()->isSecure()
        // and getSchemeAndHttpHost() reflect the real https:// scheme, which the
        // media URLs and the Secure/SameSite refresh cookie both depend on.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'auth.token' => AuthenticateAccessToken::class,
            'auth.optional' => OptionalAccessToken::class,
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
