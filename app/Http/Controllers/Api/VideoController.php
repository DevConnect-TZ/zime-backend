<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\User;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VideoController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $videos = Video::query()
            ->with('episodes')
            ->latest()
            ->get();

        return VideoResource::collection($videos);
    }

    public function trending(): AnonymousResourceCollection
    {
        return VideoResource::collection(
            Video::query()->with('episodes')->where('is_trending', true)->latest()->get()
        );
    }

    public function popular(): AnonymousResourceCollection
    {
        return VideoResource::collection(
            Video::query()->with('episodes')->where('is_popular', true)->latest()->get()
        );
    }

    public function show(Request $request, Video $video): VideoResource
    {
        return new VideoResource($video->load('episodes'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateVideo($request, creating: true);
        $episodes = $data['episodes'] ?? [];
        unset($data['episodes']);

        $data['uploaded_by'] = $request->user()->id;

        $video = DB::transaction(function () use ($data, $episodes) {
            /** @var Video $video */
            $video = Video::query()->create($data);
            $this->syncEpisodes($video, $episodes);

            return $video;
        });

        return (new VideoResource($video->load('episodes')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Video $video): VideoResource
    {
        $this->authorizeManage($request->user(), $video);

        $data = $this->validateVideo($request, creating: false);
        $episodes = $data['episodes'] ?? null;
        unset($data['episodes']);

        DB::transaction(function () use ($video, $data, $episodes) {
            $video->update($data);
            if (is_array($episodes)) {
                $video->episodes()->delete();
                $this->syncEpisodes($video, $episodes);
            }
        });

        return new VideoResource($video->load('episodes'));
    }

    public function destroy(Request $request, Video $video): JsonResponse
    {
        $this->authorizeManage($request->user(), $video);

        $video->delete();

        return response()->json(['message' => 'Video deleted.']);
    }

    /**
     * Grant a gated stream link. Requires the caller to own the item.
     */
    public function stream(Request $request, Video $video): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasUnlocked((string) $video->id, $video->type)) {
            return response()->json(['message' => 'Purchase required to stream this content.'], 403);
        }

        if (empty($video->video_link)) {
            return response()->json(['message' => 'This content has no playable source yet.'], 404);
        }

        $video->increment('views');

        return response()->json([
            'data' => [
                'url' => $video->video_link,
                'expires_at' => now()->addHours(6)->toIso8601String(),
            ],
        ]);
    }

    private function authorizeManage(User $user, Video $video): void
    {
        // Admins manage everything; uploaders manage only their own uploads.
        if ($user->isAdmin()) {
            return;
        }

        abort_unless(
            $user->isUploader() && $video->uploaded_by === $user->id,
            403,
            'You can only manage your own uploads.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validateVideo(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::in(['single', 'series'])],
            'price' => [$required, 'numeric', 'min:0', 'max:100000000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'thumbnail' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'trailer_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'video_link' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'genre' => ['sometimes', 'nullable', 'string', 'max:120'],
            'rating' => ['sometimes', 'nullable', 'string', 'max:20'],
            'duration' => ['sometimes', 'nullable', 'string', 'max:40'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'is_trending' => ['sometimes', 'boolean'],
            'is_popular' => ['sometimes', 'boolean'],
            'episodes' => ['sometimes', 'array'],
            'episodes.*.title' => ['required_with:episodes', 'string', 'max:255'],
            'episodes.*.season' => ['sometimes', 'integer', 'min:1'],
            'episodes.*.episode' => ['sometimes', 'integer', 'min:1'],
            'episodes.*.duration' => ['sometimes', 'nullable', 'string', 'max:40'],
            'episodes.*.video_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $episodes
     */
    private function syncEpisodes(Video $video, array $episodes): void
    {
        foreach ($episodes as $episode) {
            $video->episodes()->create([
                'title' => $episode['title'],
                'season' => $episode['season'] ?? 1,
                'episode' => $episode['episode'] ?? 1,
                'duration' => $episode['duration'] ?? null,
                'video_url' => $episode['video_url'] ?? null,
            ]);
        }
    }
}
