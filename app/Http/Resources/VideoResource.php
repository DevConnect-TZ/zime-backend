<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Video
 */
class VideoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $isManager = $user?->isUploader() ?? false;
        $owns = $user ? $user->hasUnlocked((string) $this->id, $this->type) : false;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'price' => (float) $this->price,
            'thumbnail' => $this->thumbnail,
            'trailer_url' => $this->trailer_url,
            'genre' => $this->genre,
            'rating' => $this->rating,
            'duration' => $this->duration,
            'year' => $this->year,
            'is_trending' => $this->is_trending,
            'is_popular' => $this->is_popular,
            'views' => $this->views,
            'purchased' => $owns,
            'seasons' => $this->whenLoaded('episodes', fn () => $this->episodes->pluck('season')->unique()->count()),
            'episodes' => EpisodeResource::collection($this->whenLoaded('episodes')),

            // The raw source link is only exposed to admins/uploaders (for editing).
            // Regular playback goes through the gated /videos/{id}/stream endpoint.
            'video_link' => $this->when($isManager, $this->video_link),
        ];
    }
}
