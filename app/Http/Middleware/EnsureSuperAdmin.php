<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Team management is reserved for Super Admin accounts. Authorization lives
 * on the server so hiding the Team navigation item is never the only guard.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! ($user->isSuperAdmin() || $user->isAdmin())) {
            abort(403, 'Only Admin accounts can manage the team.');
        }

        return $next($request);
    }
}
