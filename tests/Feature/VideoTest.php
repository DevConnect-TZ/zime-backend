<?php

use App\Models\Purchase;
use App\Models\User;
use App\Models\Video;

it('lists videos publicly without exposing the raw video link', function () {
    Video::factory()->create(['title' => 'Public Movie']);

    $response = $this->getJson('/api/videos')->assertOk();

    expect($response->json())->toBeArray();
    $response->assertJsonPath('0.title', 'Public Movie');
    $response->assertJsonMissingPath('0.video_link');
});

it('exposes the video link to admins for editing', function () {
    $admin = User::factory()->admin()->create();
    Video::factory()->create(['video_link' => 'https://cdn.example.com/master.mp4']);

    $this->getJson('/api/videos', tokenHeader($admin))
        ->assertOk()
        ->assertJsonPath('0.video_link', 'https://cdn.example.com/master.mp4');
});

it('filters trending and popular videos', function () {
    Video::factory()->trending()->create();
    Video::factory()->popular()->create();
    Video::factory()->create();

    $this->getJson('/api/videos/trending')->assertOk()->assertJsonCount(1);
    $this->getJson('/api/videos/popular')->assertOk()->assertJsonCount(1);
});

it('forbids regular users from creating videos', function () {
    $user = User::factory()->create();

    $this->postJson('/api/videos', ['title' => 'X', 'type' => 'single', 'price' => 1000], tokenHeader($user))
        ->assertStatus(403);
});

it('allows an uploader to create a video', function () {
    $uploader = User::factory()->uploader()->create();

    $this->postJson('/api/videos', [
        'title' => 'Uploader Movie',
        'type' => 'single',
        'price' => 5000,
        'video_link' => 'https://cdn.example.com/movie.mp4',
    ], tokenHeader($uploader))->assertCreated();

    $this->assertDatabaseHas('videos', ['title' => 'Uploader Movie', 'uploaded_by' => $uploader->id]);
});

it('prevents an uploader from editing another uploaders video', function () {
    $owner = User::factory()->uploader()->create();
    $other = User::factory()->uploader()->create();
    $video = Video::factory()->create(['uploaded_by' => $owner->id]);

    $this->putJson("/api/videos/{$video->id}", ['title' => 'Hijacked'], tokenHeader($other))
        ->assertStatus(403);
});

it('lets an admin toggle trending with a partial payload', function () {
    $admin = User::factory()->admin()->create();
    $video = Video::factory()->create(['is_trending' => false]);

    $this->putJson("/api/videos/{$video->id}", ['is_trending' => true], tokenHeader($admin))
        ->assertOk()
        ->assertJsonPath('is_trending', true);
});

it('hides episode sources until the series is purchased', function () {
    $user = User::factory()->create();
    $series = Video::factory()->series()->create();
    $series->episodes()->create([
        'title' => 'Pilot', 'season' => 1, 'episode' => 1,
        'video_url' => 'https://cdn.example.com/ep1.mp4',
    ]);

    // Guest and non-buyer see the listing but no playable source.
    $this->getJson("/api/videos/{$series->id}")
        ->assertOk()
        ->assertJsonPath('episodes.0.title', 'Pilot')
        ->assertJsonMissingPath('episodes.0.video_url');

    $this->getJson("/api/videos/{$series->id}", tokenHeader($user))
        ->assertJsonMissingPath('episodes.0.video_url');

    Purchase::create(['user_id' => $user->id, 'item_id' => (string) $series->id, 'item_type' => 'series']);

    $this->getJson("/api/videos/{$series->id}", tokenHeader($user->fresh()))
        ->assertOk()
        ->assertJsonPath('episodes.0.video_url', 'https://cdn.example.com/ep1.mp4')
        ->assertJsonPath('seasons', 1);
});

it('gates streaming behind a purchase', function () {
    $user = User::factory()->create();
    $video = Video::factory()->create(['video_link' => 'https://cdn.example.com/movie.mp4']);

    $this->postJson("/api/videos/{$video->id}/stream", [], tokenHeader($user))
        ->assertStatus(403);

    Purchase::create(['user_id' => $user->id, 'item_id' => (string) $video->id, 'item_type' => 'single']);

    $this->postJson("/api/videos/{$video->id}/stream", [], tokenHeader($user))
        ->assertOk()
        ->assertJsonPath('data.url', 'https://cdn.example.com/movie.mp4');
});
