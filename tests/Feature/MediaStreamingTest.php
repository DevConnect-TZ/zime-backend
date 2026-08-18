<?php

use App\Models\Purchase;
use App\Models\User;
use App\Models\Video;
use App\Services\TokenService;
use Illuminate\Support\Facades\Storage;

function mediaToken(User $user): array
{
    return ['token' => app(TokenService::class)->issueAccessToken($user)];
}

it('serves an uploaded video file through the app media route for a logged-in user', function () {
    Storage::fake('public');
    Storage::disk('public')->put('videos/clip.mp4', 'FAKE-MP4-BODY');
    $user = User::factory()->create();

    $this->get('/api/media/videos/clip.mp4?'.http_build_query(mediaToken($user)))
        ->assertOk()
        ->assertHeader('accept-ranges', 'bytes');
});

it('rejects unauthenticated access to trailers and videos but keeps thumbnails public', function () {
    Storage::fake('public');
    Storage::disk('public')->put('trailers/clip.mp4', 'FAKE-MP4-BODY');
    Storage::disk('public')->put('videos/clip.mp4', 'FAKE-MP4-BODY');
    Storage::disk('public')->put('thumbnails/poster.jpg', 'FAKE-JPG-BODY');

    $this->get('/api/media/trailers/clip.mp4')->assertUnauthorized();
    $this->get('/api/media/videos/clip.mp4')->assertUnauthorized();

    $this->get('/api/media/thumbnails/poster.jpg')->assertOk();
});

it('honours range requests so the browser can seek', function () {
    Storage::fake('public');
    Storage::disk('public')->put('videos/clip.mp4', 'ABCDEFGHIJ');
    $user = User::factory()->create();

    $response = $this->get('/api/media/videos/clip.mp4?'.http_build_query(mediaToken($user)), ['Range' => 'bytes=0-3']);

    $response->assertStatus(206);
    expect($response->headers->get('content-range'))->toBe('bytes 0-3/10');
});

it('blocks path traversal and non-media folders', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    $this->get('/api/media/secrets/keys.txt')->assertNotFound();
    $this->get('/api/media/videos/missing.mp4?'.http_build_query(mediaToken($user)))->assertNotFound();
});

it('rewrites a stored storage URL into an app media URL when streaming', function () {
    $user = User::factory()->create();
    $video = Video::factory()->create(['video_link' => 'http://localhost:8000/storage/videos/movie.mp4']);
    Purchase::create(['user_id' => $user->id, 'item_id' => (string) $video->id, 'item_type' => 'single']);

    $this->postJson("/api/videos/{$video->id}/stream", [], tokenHeader($user->fresh()))
        ->assertOk()
        ->assertJsonPath('data.url', fn ($url) => str_contains($url, '/api/media/videos/movie.mp4?token='));
});
