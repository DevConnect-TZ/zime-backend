<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
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

        // Episodes may be supplied inline so a whole series (parent + all
        // episodes) is created in a single request. Later edits go through the
        // dedicated episode endpoints.
        $episodes = $data['episodes'] ?? [];
        unset($data['episodes']);
        $data['uploaded_by'] = $request->user()->id;

        $video = DB::transaction(function () use ($data, $episodes) {
            /** @var Video $video */
            $video = Video::query()->create($data);

            foreach ($episodes as $episode) {
                $video->episodes()->create([
                    'title' => $episode['title'],
                    'season' => $episode['season'] ?? 1,
                    'episode' => $episode['episode'] ?? 1,
                    'duration' => $episode['duration'] ?? null,
                    'video_url' => $episode['video_url'] ?? null,
                ]);
            }

            return $video;
        });

        return (new VideoResource($video->load('episodes')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Video $video): VideoResource
    {
        $video->assertManageableBy($request->user());

        $data = $this->validateVideo($request, creating: false);
        $video->update($data);

        return new VideoResource($video->load('episodes'));
    }

    public function destroy(Request $request, Video $video): JsonResponse
    {
        $video->assertManageableBy($request->user());

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
                'url' => MediaController::playableUrl($request, $video->video_link),
                'expires_at' => now()->addHours(6)->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateVideo(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
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
        ];

        // Inline episodes are only accepted at creation time; existing series
        // manage their episodes through the dedicated episode endpoints.
        if ($creating) {
            $rules['episodes'] = ['sometimes', 'array'];
            $rules['episodes.*.title'] = ['required_with:episodes', 'string', 'max:255'];
            $rules['episodes.*.season'] = ['sometimes', 'integer', 'min:1'];
            $rules['episodes.*.episode'] = ['sometimes', 'integer', 'min:1'];
            $rules['episodes.*.duration'] = ['sometimes', 'nullable', 'string', 'max:40'];
            $rules['episodes.*.video_url'] = ['sometimes', 'nullable', 'string', 'max:2048'];
        }

        return $request->validate($rules);
    }
}
