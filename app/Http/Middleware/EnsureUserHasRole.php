<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class EnsureUserHasRole
{
    /**
     * Usage: ->middleware('role:admin') or 'role:admin,uploader'.
     * Admin implicitly satisfies every role check.
     */
    public function handle(Request $request, Closure $next, string ...$roles): SymfonyResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($user->isAdmin() || in_array($user->role, $roles, true)) {
            return $next($request);
        }

        return response()->json(['message' => 'You do not have permission to perform this action.'], Response::HTTP_FORBIDDEN);
    }
}
