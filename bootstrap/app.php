<?php

use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTesterScope;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tester.scope' => EnsureTesterScope::class,
            'super.admin' => EnsureSuperAdmin::class,
        ]);

        // Rate limiting has to run before authentication. Laravel's default
        // priority list puts Authenticate above ThrottleRequests, so a 401 would
        // short-circuit the limiter and leave protected routes unthrottled for
        // unauthenticated traffic. This mirrors the default order with those two
        // entries swapped.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesRequests::class,
            AuthenticatesSessions::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);

        // Applies the "api" rate limiter (defined in AppServiceProvider) to every API route.
        $middleware->throttleApi('api');

        // Decoupled SPA authentication redirect handling: return JSON 401 instead of Route [login] exception
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return null;
            }

            return '/login';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
