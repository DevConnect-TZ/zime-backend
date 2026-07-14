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

        // Uploaders/admins need the source to edit; buyers of the parent series
        // need it to watch. Everyone else gets a locked episode listing.
        $canSeeSource = $user !== null && (
            $user->isUploader() || $user->hasUnlocked((string) $this->video_id, 'series')
        );

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
