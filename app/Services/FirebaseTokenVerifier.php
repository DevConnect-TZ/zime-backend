<?php

namespace App\Services;

use App\Exceptions\InvalidFirebaseTokenException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifies Firebase Authentication ID tokens (RS256 JWTs signed by Google)
 * without requiring the Firebase Admin service account. Only the public
 * certificates and the project id are needed to validate a token.
 *
 * @phpstan-type FirebaseClaims array{sub: string, email?: string, name?: string, picture?: string, email_verified?: bool}
 */
class FirebaseTokenVerifier
{
    private const CERT_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    private const CACHE_KEY = 'firebase_public_certs';

    public function __construct(private readonly ?string $projectId)
    {
    }

    /**
     * @return FirebaseClaims
     *
     * @throws InvalidFirebaseTokenException
     */
    public function verify(string $idToken): array
    {
        if (empty($this->projectId)) {
            throw new InvalidFirebaseTokenException('Firebase project id is not configured.');
        }

        $segments = explode('.', $idToken);
        if (count($segments) !== 3) {
            throw new InvalidFirebaseTokenException('Malformed token.');
        }

        [$rawHeader, $rawPayload, $rawSignature] = $segments;

        $header = $this->decodeSegment($rawHeader);
        $payload = $this->decodeSegment($rawPayload);
        $signature = $this->base64UrlDecode($rawSignature);

        if (($header['alg'] ?? null) !== 'RS256' || empty($header['kid'])) {
            throw new InvalidFirebaseTokenException('Unexpected token algorithm.');
        }

        $publicKey = $this->publicKeyForKid($header['kid']);

        $signingInput = $rawHeader.'.'.$rawPayload;
        $verified = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($verified !== 1) {
            throw new InvalidFirebaseTokenException('Invalid token signature.');
        }

        $this->assertClaims($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidFirebaseTokenException
     */
    private function assertClaims(array $payload): void
    {
        $now = time();
        $leeway = 60;

        if (($payload['exp'] ?? 0) < ($now - $leeway)) {
            throw new InvalidFirebaseTokenException('Token has expired.');
        }

        if (($payload['iat'] ?? 0) > ($now + $leeway)) {
            throw new InvalidFirebaseTokenException('Token issued in the future.');
        }

        if (($payload['aud'] ?? null) !== $this->projectId) {
            throw new InvalidFirebaseTokenException('Token audience mismatch.');
        }

        if (($payload['iss'] ?? null) !== 'https://securetoken.google.com/'.$this->projectId) {
            throw new InvalidFirebaseTokenException('Token issuer mismatch.');
        }

        if (empty($payload['sub'])) {
            throw new InvalidFirebaseTokenException('Token subject missing.');
        }
    }

    /**
     * @return \OpenSSLAsymmetricKey|string
     *
     * @throws InvalidFirebaseTokenException
     */
    private function publicKeyForKid(string $kid)
    {
        $certs = $this->fetchCerts();

        if (! isset($certs[$kid])) {
            // Cache may be stale after Google rotates keys; refresh once.
            Cache::forget(self::CACHE_KEY);
            $certs = $this->fetchCerts();
        }

        if (! isset($certs[$kid])) {
            throw new InvalidFirebaseTokenException('Signing key not found.');
        }

        $key = openssl_pkey_get_public($certs[$kid]);
        if ($key === false) {
            throw new InvalidFirebaseTokenException('Unable to parse signing key.');
        }

        return $key;
    }

    /**
     * @return array<string, string>
     *
     * @throws InvalidFirebaseTokenException
     */
    private function fetchCerts(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHour(), function () {
            $response = Http::timeout(10)->get(self::CERT_URL);

            if (! $response->successful()) {
                throw new InvalidFirebaseTokenException('Unable to retrieve Google public certificates.');
            }

            /** @var array<string, string> $certs */
            $certs = $response->json();

            return $certs;
        });
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidFirebaseTokenException
     */
    private function decodeSegment(string $segment): array
    {
        $decoded = json_decode($this->base64UrlDecode($segment), true);

        if (! is_array($decoded)) {
            throw new InvalidFirebaseTokenException('Malformed token segment.');
        }

        return $decoded;
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
