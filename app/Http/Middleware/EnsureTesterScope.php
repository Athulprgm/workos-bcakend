<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tester accounts are deliberately narrow: they may only call the Testing API
 * and manage their own session. Every other module is blocked server-side, so
 * hiding UI links is never the only protection.
 */
class EnsureTesterScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isTester()) {
            return $next($request);
        }

        $allowed = $request->is('api/testing', 'api/testing/*')
            || $request->is('api/auth/me', 'api/auth/logout');

        if (! $allowed) {
            abort(403, 'Tester accounts may only access the Testing module.');
        }

        if (! $user->hasActiveTesterAccess()) {
            abort(403, 'Your tester access has been revoked. Contact the workspace owner.');
        }

        return $next($request);
    }
}
