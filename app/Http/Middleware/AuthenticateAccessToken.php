<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\TokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AuthenticateAccessToken
{
    public function __construct(private readonly TokenService $tokens)
    {
    }

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->unauthenticated('Authentication required.');
        }

        $userId = $this->tokens->parseAccessToken($token);

        if (! $userId) {
            return $this->unauthenticated('Invalid or expired token.');
        }

        // Purchases are eager-loaded once so downstream resources can resolve
        // unlock state without querying per item.
        $user = User::query()->with('purchases')->find($userId);

        if (! $user) {
            return $this->unauthenticated('User not found.');
        }

        if ($user->isBanned()) {
            return response()->json(['message' => 'Your account has been suspended.'], Response::HTTP_FORBIDDEN);
        }

        // Make the user resolvable via $request->user() / auth() for downstream code.
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    private function unauthenticated(string $message): SymfonyResponse
    {
        return response()->json(['message' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
