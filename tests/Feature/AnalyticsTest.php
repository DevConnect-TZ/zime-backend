<?php

use App\Models\Purchase;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoPlay;

it('records a play when a single video is streamed', function () {
    $user = User::factory()->create();
    $video = Video::factory()->create(['video_link' => 'https://cdn/m.mp4', 'type' => 'single', 'views' => 0]);
    Purchase::create(['user_id' => $user->id, 'item_id' => (string) $video->id, 'item_type' => 'single']);

    $this->postJson("/api/videos/{$video->id}/stream", [], tokenHeader($user))->assertOk();

    $this->assertDatabaseHas('video_plays', [
        'video_id' => $video->id,
        'episode_id' => null,
        'user_id' => $user->id,
    ]);
    $this->assertDatabaseHas('videos', ['id' => $video->id, 'views' => 1]);
});

it('records a play when an episode is streamed', function () {
    $user = User::factory()->create();
    $series = Video::factory()->series()->create();
    $episode = $series->episodes()->create(['title' => 'E1', 'season' => 1, 'episode' => 1, 'video_url' => 'https://cdn/e.mp4']);
    Purchase::create(['user_id' => $user->id, 'item_id' => (string) $series->id, 'item_type' => 'series']);

    $this->postJson("/api/videos/{$series->id}/episodes/{$episode->id}/stream", [], tokenHeader($user))->assertOk();

    $this->assertDatabaseHas('video_plays', [
        'video_id' => $series->id,
        'episode_id' => $episode->id,
        'user_id' => $user->id,
    ]);
});

it('returns daily, weekly and total play analytics to admins only', function () {
    $user = User::factory()->create();
    $video = Video::factory()->create(['views' => 5]);

    VideoPlay::create(['video_id' => $video->id, 'user_id' => $user->id, 'played_at' => now()]);
    VideoPlay::create(['video_id' => $video->id, 'user_id' => $user->id, 'played_at' => now()->subDays(3)]);
    VideoPlay::create(['video_id' => $video->id, 'user_id' => $user->id, 'played_at' => now()->subWeeks(3)]);

    $this->getJson('/api/admin/analytics', tokenHeader($user))->assertStatus(403);

    $admin = User::factory()->admin()->create();
    $response = $this->getJson('/api/admin/analytics', tokenHeader($admin))->assertOk();

    expect($response->json('data.totals.plays'))->toBe(3);
    expect($response->json('data.totals.views'))->toBe(5);
    expect(count($response->json('data.daily')))->toBe(30);
    expect(count($response->json('data.weekly')))->toBe(12);
    expect($response->json('data.top_videos.0.title'))->toBe($video->title);
    expect($response->json('data.top_videos.0.plays'))->toBe(3);
});
