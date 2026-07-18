<?php

use App\Models\User;
use App\Models\Video;

it('creates a series and all its episodes in a single request', function () {
    $uploader = User::factory()->uploader()->create();

    $response = $this->postJson('/api/videos', [
        'title' => 'One Shot Series',
        'type' => 'series',
        'price' => 15000,
        'thumbnail' => 'https://cdn.example.com/poster.jpg',
        'episodes' => [
            ['title' => 'Pilot', 'season' => 1, 'episode' => 1, 'video_url' => 'https://cdn.example.com/s1e1.mp4'],
            ['title' => 'The Twist', 'season' => 1, 'episode' => 2, 'video_url' => 'https://cdn.example.com/s1e2.mp4'],
            ['title' => 'Season Two Opener', 'season' => 2, 'episode' => 1, 'video_url' => 'https://cdn.example.com/s2e1.mp4'],
        ],
    ], tokenHeader($uploader))->assertCreated();

    $seriesId = $response->json('id');

    $this->assertDatabaseHas('videos', ['id' => $seriesId, 'title' => 'One Shot Series', 'type' => 'series']);
    expect(Video::find($seriesId)->episodes()->count())->toBe(3);
    $response->assertJsonPath('seasons', 2);
    $response->assertJsonCount(3, 'episodes');
});

it('rolls back the whole series when an inline episode is invalid', function () {
    $uploader = User::factory()->uploader()->create();

    $this->postJson('/api/videos', [
        'title' => 'Bad Series',
        'type' => 'series',
        'price' => 15000,
        'episodes' => [
            ['title' => 'Good', 'season' => 1, 'episode' => 1],
            ['season' => 1, 'episode' => 2], // missing required title
        ],
    ], tokenHeader($uploader))->assertStatus(422);

    $this->assertDatabaseMissing('videos', ['title' => 'Bad Series']);
});

it('lets an uploader add an episode to their own series after it was created', function () {
    $uploader = User::factory()->uploader()->create();
    $series = Video::factory()->series()->create(['uploaded_by' => $uploader->id]);

    $this->postJson("/api/videos/{$series->id}/episodes", [
        'title' => 'Pilot',
        'season' => 1,
        'episode' => 1,
        'video_url' => 'https://cdn.example.com/ep1.mp4',
    ], tokenHeader($uploader))->assertCreated();

    $this->assertDatabaseHas('episodes', ['video_id' => $series->id, 'title' => 'Pilot']);

    $this->postJson("/api/videos/{$series->id}/episodes", [
        'title' => 'Episode Two',
        'season' => 1,
        'episode' => 2,
        'video_url' => 'https://cdn.example.com/ep2.mp4',
    ], tokenHeader($uploader))->assertCreated();

    // Adding a second episode must not disturb the first (no destructive replace).
    expect($series->episodes()->count())->toBe(2);
});

it('rejects adding an episode to a single (non-series) video', function () {
    $uploader = User::factory()->uploader()->create();
    $single = Video::factory()->create(['uploaded_by' => $uploader->id, 'type' => 'single']);

    $this->postJson("/api/videos/{$single->id}/episodes", [
        'title' => 'Pilot',
    ], tokenHeader($uploader))->assertStatus(422);
});

it('prevents an uploader from adding episodes to another uploaders series', function () {
    $owner = User::factory()->uploader()->create();
    $other = User::factory()->uploader()->create();
    $series = Video::factory()->series()->create(['uploaded_by' => $owner->id]);

    $this->postJson("/api/videos/{$series->id}/episodes", [
        'title' => 'Hijacked',
    ], tokenHeader($other))->assertStatus(403);
});

it('lets an uploader update and delete an episode', function () {
    $uploader = User::factory()->uploader()->create();
    $series = Video::factory()->series()->create(['uploaded_by' => $uploader->id]);
    $episode = $series->episodes()->create(['title' => 'Draft Title', 'season' => 1, 'episode' => 1]);

    $this->putJson("/api/videos/{$series->id}/episodes/{$episode->id}", [
        'title' => 'Final Title',
    ], tokenHeader($uploader))
        ->assertOk()
        ->assertJsonPath('title', 'Final Title');

    $this->deleteJson("/api/videos/{$series->id}/episodes/{$episode->id}", [], tokenHeader($uploader))
        ->assertOk();

    $this->assertDatabaseMissing('episodes', ['id' => $episode->id]);
});

it('404s when the episode does not belong to the given video', function () {
    $uploader = User::factory()->uploader()->create();
    $seriesA = Video::factory()->series()->create(['uploaded_by' => $uploader->id]);
    $seriesB = Video::factory()->series()->create(['uploaded_by' => $uploader->id]);
    $episode = $seriesA->episodes()->create(['title' => 'Pilot', 'season' => 1, 'episode' => 1]);

    $this->putJson("/api/videos/{$seriesB->id}/episodes/{$episode->id}", [
        'title' => 'Mismatched',
    ], tokenHeader($uploader))->assertStatus(404);
});
