<?php

namespace App\Http\Resources;

use App\Models\Episode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Episode
 */
class EpisodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        // Only catalogue managers receive the stored source for editing.
        // Viewers, including buyers, obtain a gated link from the episode
        // stream endpoint so catalogue responses never expose every source.
        $canSeeSource = $user?->isUploader() ?? false;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'season' => $this->season,
            'episode' => $this->episode,
            'duration' => $this->duration,
            'video_url' => $this->when($canSeeSource, $this->video_url),
        ];
    }
}
