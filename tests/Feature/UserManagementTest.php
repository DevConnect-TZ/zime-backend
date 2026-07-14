<?php

use App\Models\User;
use App\Models\Video;

it('lists users for admins with unlock counts', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->count(2)->create();

    $this->getJson('/api/users', tokenHeader($admin))
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonStructure([['id', 'email', 'role', 'status', 'unlocked_videos', 'unlocked_series']]);
});

it('forbids non-admins from listing users', function () {
    $uploader = User::factory()->uploader()->create();

    $this->getJson('/api/users', tokenHeader($uploader))->assertStatus(403);
});

it('lets an admin change a users role', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->putJson("/api/users/{$user->id}/role", ['role' => 'uploader'], tokenHeader($admin))
        ->assertOk()
        ->assertJsonPath('role', 'uploader');
});

it('bans a user and revokes their refresh tokens', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $user->refreshTokens()->create([
        'token_hash' => hash('sha256', 'plain'),
        'expires_at' => now()->addDay(),
    ]);

    $this->putJson("/api/users/{$user->id}/status", ['status' => 'banned'], tokenHeader($admin))
        ->assertOk()
        ->assertJsonPath('status', 'banned');

    expect($user->refreshTokens()->whereNull('revoked_at')->count())->toBe(0);
});

it('prevents an admin from banning themselves', function () {
    $admin = User::factory()->admin()->create();

    $this->putJson("/api/users/{$admin->id}/status", ['status' => 'banned'], tokenHeader($admin))
        ->assertStatus(422);
});

it('lets an admin manually unlock content for a user', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $video = Video::factory()->series()->create();

    $this->postJson("/api/users/{$user->id}/unlock-video", ['video_id' => (string) $video->id], tokenHeader($admin))
        ->assertOk();

    $this->assertDatabaseHas('purchases', [
        'user_id' => $user->id,
        'item_id' => (string) $video->id,
        'item_type' => 'series',
    ]);
});

it('rejects banned users on authenticated requests', function () {
    $banned = User::factory()->banned()->create();

    $this->getJson('/api/transactions', tokenHeader($banned))->assertStatus(403);
});
