<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the /admin section.
 *
 * This is a coarse gate, not authorization. Every writable admin resource also
 * needs a Policy — middleware answers "may you be in this area", a policy
 * answers "may you do this to this record" (docs/engineering.md §10).
 */
final class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // 404 rather than 403: an anonymous prober learns nothing about which
        // admin routes exist. An authenticated non-admin gets the same answer.
        if (! $user instanceof User || ! $user->isAdmin()) {
            abort(404);
        }

        return $next($request);
    }
}
