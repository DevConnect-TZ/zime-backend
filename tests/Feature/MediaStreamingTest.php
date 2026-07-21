<?php

use App\Models\Purchase;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;

it('serves an uploaded video file through the app media route', function () {
    Storage::fake('public');
    Storage::disk('public')->put('videos/clip.mp4', 'FAKE-MP4-BODY');

    $this->get('/api/media/videos/clip.mp4')
        ->assertOk()
        ->assertHeader('accept-ranges', 'bytes');
});

it('honours range requests so the browser can seek', function () {
    Storage::fake('public');
    Storage::disk('public')->put('videos/clip.mp4', 'ABCDEFGHIJ');

    $response = $this->get('/api/media/videos/clip.mp4', ['Range' => 'bytes=0-3']);

    $response->assertStatus(206);
    expect($response->headers->get('content-range'))->toBe('bytes 0-3/10');
});

it('blocks path traversal and non-media folders', function () {
    Storage::fake('public');

    $this->get('/api/media/secrets/keys.txt')->assertNotFound();
    $this->get('/api/media/videos/missing.mp4')->assertNotFound();
});

it('rewrites a stored storage URL into an app media URL when streaming', function () {
    $user = User::factory()->create();
    $video = Video::factory()->create(['video_link' => 'http://localhost:8000/storage/videos/movie.mp4']);
    Purchase::create(['user_id' => $user->id, 'item_id' => (string) $video->id, 'item_type' => 'single']);

    $this->postJson("/api/videos/{$video->id}/stream", [], tokenHeader($user->fresh()))
        ->assertOk()
        ->assertJsonPath('data.url', fn ($url) => str_ends_with($url, '/api/media/videos/movie.mp4'));
});
