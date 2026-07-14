<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidFirebaseTokenException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\FirebaseTokenVerifier;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class AuthController extends Controller
{
    public function __construct(
        private readonly FirebaseTokenVerifier $firebase,
        private readonly TokenService $tokens,
    ) {
    }

    /**
     * Exchange a Firebase ID token for an application session.
     */
    public function session(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string'],
        ]);

        try {
            $claims = $this->firebase->verify($validated['id_token']);
        } catch (InvalidFirebaseTokenException $e) {
            return response()->json(['message' => 'Authentication failed: '.$e->getMessage()], 401);
        }

        $user = $this->resolveUser($claims);

        if ($user->isBanned()) {
            return response()->json(['message' => 'Your account has been suspended.'], 403);
        }

        return $this->authResponse($user, $request, [
            'access_token' => $this->tokens->issueAccessToken($user),
            'user' => new UserResource($user->load('purchases')),
        ]);
    }

    /**
     * Rotate the refresh cookie and mint a new access token.
     */
    public function refresh(Request $request): JsonResponse
    {
        $cookieName = (string) config('services.auth_tokens.refresh_cookie');
        $plain = $request->cookie($cookieName);

        $rotated = $plain ? $this->tokens->rotateRefreshToken($plain, $request) : null;

        if (! $rotated) {
            return response()->json(['message' => 'Session expired.'], 401)
                ->withCookie($this->forgetCookie());
        }

        $accessToken = $this->tokens->issueAccessToken($rotated['user']);

        return response()->json(['access_token' => $accessToken])
            ->header('X-Access-Token', $accessToken)
            ->withCookie($this->makeCookie($rotated['token']));
    }

    public function logout(Request $request): JsonResponse
    {
        $cookieName = (string) config('services.auth_tokens.refresh_cookie');

        if ($plain = $request->cookie($cookieName)) {
            $this->tokens->revokeRefreshToken($plain);
        }

        return response()->json(['message' => 'Logged out.'])
            ->withCookie($this->forgetCookie());
    }

    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user->load('purchases'));
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function resolveUser(array $claims): User
    {
        $email = isset($claims['email']) ? Str::lower((string) $claims['email']) : null;
        $bootstrapAdmin = Str::lower((string) config('services.platform.bootstrap_admin_email', ''));

        /** @var User|null $user */
        $user = User::query()->where('firebase_uid', $claims['sub'])->first();

        if (! $user && $email) {
            // Link a pre-existing account created with the same email.
            $user = User::query()->where('email', $email)->first();
        }

        $attributes = [
            'firebase_uid' => $claims['sub'],
            'name' => $claims['name'] ?? $user?->name ?? ($email ? Str::before($email, '@') : 'User'),
            'avatar' => $claims['picture'] ?? $user?->avatar,
        ];

        if ($user) {
            $user->fill($attributes);
            if ($email) {
                $user->email = $email;
            }
            $user->save();
        } else {
            $user = User::query()->create(array_merge($attributes, [
                'email' => $email ?? $claims['sub'].'@firebase.local',
                'role' => User::ROLE_USER,
                'status' => User::STATUS_ACTIVE,
            ]));
        }

        // Promote the configured bootstrap admin exactly once.
        if ($bootstrapAdmin && $email === $bootstrapAdmin && ! $user->isAdmin()) {
            $user->forceFill(['role' => User::ROLE_ADMIN])->save();
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function authResponse(User $user, Request $request, array $payload): JsonResponse
    {
        $refresh = $this->tokens->issueRefreshToken($user, $request);

        return response()->json($payload)
            ->header('X-Access-Token', $payload['access_token'])
            ->withCookie($this->makeCookie($refresh));
    }

    private function makeCookie(string $value): Cookie
    {
        $minutes = (int) (config('services.auth_tokens.refresh_ttl') / 60);

        return cookie(
            name: (string) config('services.auth_tokens.refresh_cookie'),
            value: $value,
            minutes: $minutes,
            path: '/',
            domain: null,
            secure: request()->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: request()->isSecure() ? 'none' : 'lax',
        );
    }

    private function forgetCookie(): Cookie
    {
        return cookie()->forget((string) config('services.auth_tokens.refresh_cookie'));
    }
}
