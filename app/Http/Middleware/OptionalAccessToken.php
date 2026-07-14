<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\TokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Resolves the authenticated user when a valid Bearer token is present, but
 * never rejects the request. Used on public endpoints whose response is richer
 * for an authenticated caller (e.g. exposing purchase state or the raw source
 * link to admins/uploaders).
 */
class OptionalAccessToken
{
    public function __construct(private readonly TokenService $tokens)
    {
    }

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $token = $request->bearerToken();

        if ($token && ($userId = $this->tokens->parseAccessToken($token))) {
            $user = User::query()->with('purchases')->find($userId);

            if ($user && ! $user->isBanned()) {
                $request->setUserResolver(fn () => $user);
            }
        }

        return $next($request);
    }
}
