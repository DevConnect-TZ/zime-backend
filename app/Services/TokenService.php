<?php

namespace App\Services;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Issues short-lived stateless access tokens (HMAC-signed JWTs) and long-lived
 * opaque refresh tokens persisted (hashed) in the database. Refresh tokens are
 * rotated on every use to limit replay if a cookie is ever leaked.
 */
class TokenService
{
    public function __construct(
        private readonly int $accessTtl,
        private readonly int $refreshTtl,
    ) {
    }

    public function issueAccessToken(User $user): string
    {
        $now = time();

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'sub' => $user->id,
            'role' => $user->role,
            'iat' => $now,
            'exp' => $now + $this->accessTtl,
            'jti' => Str::uuid()->toString(),
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header)),
            $this->base64UrlEncode(json_encode($payload)),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), $this->signingKey(), true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * Returns the authenticated user id if the token is valid and unexpired.
     */
    public function parseAccessToken(string $token): ?int
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            return null;
        }

        [$rawHeader, $rawPayload, $rawSignature] = $segments;

        $expected = hash_hmac('sha256', $rawHeader.'.'.$rawPayload, $this->signingKey(), true);
        if (! hash_equals($expected, $this->base64UrlDecode($rawSignature))) {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($rawPayload), true);
        if (! is_array($payload) || ($payload['exp'] ?? 0) < time()) {
            return null;
        }

        return isset($payload['sub']) ? (int) $payload['sub'] : null;
    }

    /**
     * Creates a refresh token record and returns the plaintext token that must
     * be delivered to the client (only the hash is stored).
     */
    public function issueRefreshToken(User $user, Request $request): string
    {
        $plain = Str::random(64);

        RefreshToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            'ip_address' => $request->ip(),
            'expires_at' => now()->addSeconds($this->refreshTtl),
        ]);

        return $plain;
    }

    /**
     * Validates and rotates a refresh token. Returns the owning user and a fresh
     * plaintext refresh token, or null if the presented token is invalid.
     *
     * @return array{user: User, token: string}|null
     */
    public function rotateRefreshToken(string $plain, Request $request): ?array
    {
        $record = RefreshToken::query()
            ->where('token_hash', hash('sha256', $plain))
            ->first();

        if (! $record || ! $record->isActive()) {
            return null;
        }

        $user = $record->user;
        if (! $user || $user->isBanned()) {
            return null;
        }

        $record->forceFill(['revoked_at' => now()])->save();

        return [
            'user' => $user,
            'token' => $this->issueRefreshToken($user, $request),
        ];
    }

    public function revokeRefreshToken(string $plain): void
    {
        RefreshToken::query()
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        // Namespaced derivation so access-token signing never collides with other APP_KEY uses.
        return hash('sha256', 'access-token|'.$key, true);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
