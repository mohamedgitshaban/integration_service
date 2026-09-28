<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route group to one account role, e.g. `role:instructor`.
 */
class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        abort_unless($request->user()?->role === $role, Response::HTTP_FORBIDDEN, "This endpoint is only available to {$role}s.");

        return $next($request);
    }
}
