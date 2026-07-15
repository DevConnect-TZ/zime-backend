<?php

namespace App\Models;

use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title', 'description', 'type', 'price', 'thumbnail', 'trailer_url',
    'video_link', 'genre', 'rating', 'duration', 'year',
    'is_trending', 'is_popular', 'views', 'uploaded_by',
])]
class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory;

    /**
     * The video_link is the protected master source and must never be
     * serialized to clients directly; access is granted via a gated stream URL.
     */
    protected $hidden = ['video_link'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_trending' => 'boolean',
            'is_popular' => 'boolean',
            'year' => 'integer',
            'views' => 'integer',
        ];
    }

    /**
     * @return HasMany<Episode, $this>
     */
    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class)->orderBy('season')->orderBy('episode');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isSeries(): bool
    {
        return $this->type === 'series';
    }

    /**
     * Admins manage everything; uploaders manage only their own uploads.
     */
    public function assertManageableBy(User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        abort_unless(
            $user->isUploader() && $this->uploaded_by === $user->id,
            403,
            'You can only manage your own uploads.'
        );
    }
}
