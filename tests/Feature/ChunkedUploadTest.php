<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A minimal but real MP4 header so mime_content_type() on the assembled file
 * reports video/mp4, matching how a genuine upload would be detected.
 */
function mp4Bytes(string $payload): string
{
    $ftyp = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41";

    return $ftyp.$payload;
}

it('assembles chunks into a single playable video and stores it', function () {
    Storage::fake('local');
    Storage::fake('public');
    $uploader = User::factory()->uploader()->create();
    $uploadId = 'test-upload-123456';

    $chunks = [mp4Bytes(str_repeat('a', 2000)), str_repeat('b', 2000), str_repeat('c', 2000)];
    $total = count($chunks);
    $response = null;

    foreach ($chunks as $index => $bytes) {
        $response = $this->post('/api/uploads/video/chunk', [
            'chunk' => UploadedFile::fake()->createWithContent("part{$index}", $bytes),
            'upload_id' => $uploadId,
            'chunk_index' => $index,
            'total_chunks' => $total,
            'extension' => 'mp4',
            'folder' => 'videos',
        ], tokenHeader($uploader));
    }

    $response->assertOk()->assertJsonStructure(['url', 'path']);

    $path = $response->json('path');
    expect($path)->toStartWith('videos/');
    Storage::disk('public')->assertExists($path);
    // The stored file is the three chunks concatenated in order.
    expect(Storage::disk('public')->get($path))->toBe(mp4Bytes(str_repeat('a', 2000)).str_repeat('b', 2000).str_repeat('c', 2000));
    // Scratch chunks are cleaned up once assembled.
    Storage::disk('local')->assertMissing('chunks/'.$uploadId);
});

it('acknowledges intermediate chunks without assembling yet', function () {
    Storage::fake('local');
    Storage::fake('public');
    $uploader = User::factory()->uploader()->create();

    $this->post('/api/uploads/video/chunk', [
        'chunk' => UploadedFile::fake()->createWithContent('part0', mp4Bytes('x')),
        'upload_id' => 'partial-upload-1',
        'chunk_index' => 0,
        'total_chunks' => 3,
        'extension' => 'mp4',
    ], tokenHeader($uploader))
        ->assertOk()
        ->assertJsonPath('status', 'received');
});

it('rejects an assembled file that is not a real video', function () {
    Storage::fake('local');
    Storage::fake('public');
    $uploader = User::factory()->uploader()->create();

    $this->post('/api/uploads/video/chunk', [
        'chunk' => UploadedFile::fake()->createWithContent('part0', 'just plain text, not a video'),
        'upload_id' => 'bad-upload-1',
        'chunk_index' => 0,
        'total_chunks' => 1,
        'extension' => 'mp4',
    ], tokenHeader($uploader))->assertStatus(422);
});

it('forbids non-uploaders from chunked uploads', function () {
    $user = User::factory()->create();

    $this->post('/api/uploads/video/chunk', [
        'chunk' => UploadedFile::fake()->createWithContent('part0', mp4Bytes('x')),
        'upload_id' => 'forbidden-upload-1',
        'chunk_index' => 0,
        'total_chunks' => 1,
        'extension' => 'mp4',
    ], tokenHeader($user))->assertStatus(403);
});
