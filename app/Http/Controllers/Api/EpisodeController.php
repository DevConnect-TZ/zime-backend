<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EpisodeResource;
use App\Models\Episode;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EpisodeController extends Controller
{
    public function stream(Request $request, Video $video, Episode $episode): JsonResponse
    {
        $this->assertBelongsToVideo($video, $episode);
        abort_unless($video->isSeries(), 404);

        if (! $request->user()->hasUnlocked((string) $video->id, 'series')) {
            return response()->json(['message' => 'Purchase required to stream this episode.'], 403);
        }

        if (empty($episode->video_url)) {
            return response()->json(['message' => 'This episode has no playable source yet.'], 404);
        }

        $video->increment('views');

        return response()->json([
            'data' => [
                'url' => MediaController::playableUrl($request, $episode->video_url),
                'expires_at' => now()->addHours(6)->toIso8601String(),
            ],
        ]);
    }

    public function store(Request $request, Video $video): JsonResponse
    {
        $video->assertManageableBy($request->user());

        abort_unless($video->isSeries(), 422, 'Episodes can only be added to a series.');

        $data = $this->validateEpisode($request, creating: true);

        $episode = $video->episodes()->create($data);

        return (new EpisodeResource($episode))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Video $video, Episode $episode): EpisodeResource
    {
        $video->assertManageableBy($request->user());
        $this->assertBelongsToVideo($video, $episode);

        $data = $this->validateEpisode($request, creating: false);
        $episode->update($data);

        return new EpisodeResource($episode);
    }

    public function destroy(Request $request, Video $video, Episode $episode): JsonResponse
    {
        $video->assertManageableBy($request->user());
        $this->assertBelongsToVideo($video, $episode);

        $episode->delete();

        return response()->json(['message' => 'Episode deleted.']);
    }

    private function assertBelongsToVideo(Video $video, Episode $episode): void
    {
        abort_unless($episode->video_id === $video->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateEpisode(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'season' => ['sometimes', 'integer', 'min:1'],
            'episode' => ['sometimes', 'integer', 'min:1'],
            'duration' => ['sometimes', 'nullable', 'string', 'max:40'],
            'video_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);
    }
}
