<?php

use App\Models\User;
use App\Services\FirebaseTokenVerifier;

beforeEach(function () {
    config()->set('services.platform.bootstrap_admin_email', 'admin@zime.app');
});

function fakeFirebase(array $claims): void
{
    test()->mock(FirebaseTokenVerifier::class, function ($mock) use ($claims) {
        $mock->shouldReceive('verify')->andReturn($claims);
    });
}

it('creates a session and user from a valid firebase token', function () {
    fakeFirebase([
        'sub' => 'firebase-uid-1',
        'email' => 'viewer@example.com',
        'name' => 'Viewer One',
    ]);

    $response = $this->postJson('/api/auth/session', ['id_token' => 'valid-token']);

    $response->assertOk()
        ->assertJsonPath('user.email', 'viewer@example.com')
        ->assertJsonPath('user.role', 'user')
        ->assertJsonStructure(['access_token', 'user' => ['id', 'unlocked_videos', 'unlocked_series']]);

    expect($response->headers->get('X-Access-Token'))->not->toBeNull();
    $this->assertNotNull($response->headers->getCookies()[0] ?? null);

    $this->assertDatabaseHas('users', ['firebase_uid' => 'firebase-uid-1', 'email' => 'viewer@example.com']);
    $this->assertDatabaseCount('refresh_tokens', 1);
});

it('promotes the configured bootstrap admin', function () {
    fakeFirebase(['sub' => 'admin-uid', 'email' => 'admin@zime.app', 'name' => 'Boss']);

    $this->postJson('/api/auth/session', ['id_token' => 'valid-token'])
        ->assertOk()
        ->assertJsonPath('user.role', 'admin');
});

it('rejects an invalid firebase token', function () {
    $this->mock(FirebaseTokenVerifier::class, function ($mock) {
        $mock->shouldReceive('verify')
            ->andThrow(new App\Exceptions\InvalidFirebaseTokenException('Invalid token signature.'));
    });

    $this->postJson('/api/auth/session', ['id_token' => 'tampered'])
        ->assertStatus(401);
});

it('blocks banned users from creating a session', function () {
    User::factory()->banned()->create([
        'email' => 'banned@example.com',
        'firebase_uid' => 'banned-uid',
    ]);

    fakeFirebase(['sub' => 'banned-uid', 'email' => 'banned@example.com']);

    $this->postJson('/api/auth/session', ['id_token' => 'valid-token'])
        ->assertStatus(403);
});

it('rotates the refresh cookie and issues a fresh access token', function () {
    fakeFirebase(['sub' => 'uid-refresh', 'email' => 'refresh@example.com']);

    $session = $this->postJson('/api/auth/session', ['id_token' => 'valid-token'])->assertOk();
    $cookie = $session->headers->getCookies()[0];

    // Send the cookie exactly as a browser would (plaintext, no client-side encryption).
    $refresh = $this->call('POST', '/api/auth/refresh', [], [$cookie->getName() => $cookie->getValue()], [], [
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $refresh->assertOk();
    expect($refresh->headers->get('X-Access-Token'))->not->toBeNull();

    // Old token is revoked (rotation), a new one issued.
    $this->assertDatabaseCount('refresh_tokens', 2);
    expect(App\Models\RefreshToken::whereNotNull('revoked_at')->count())->toBe(1);
});

it('rejects refresh without a valid cookie', function () {
    $this->postJson('/api/auth/refresh')->assertStatus(401);
});

it('returns the authenticated user from /me', function () {
    $user = User::factory()->create();

    $this->getJson('/api/auth/me', tokenHeader($user))
        ->assertOk()
        ->assertJsonPath('email', $user->email);
});
