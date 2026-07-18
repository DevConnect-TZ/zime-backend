<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'firebase_uid', 'role', 'status', 'avatar'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_UPLOADER = 'uploader';

    public const ROLE_USER = 'user';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_BANNED = 'banned';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasMany<Purchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<RefreshToken, $this>
     */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isUploader(): bool
    {
        return $this->role === self::ROLE_UPLOADER || $this->isAdmin();
    }

    public function isBanned(): bool
    {
        return $this->status === self::STATUS_BANNED;
    }

    /**
     * Prefers the already-loaded purchases relation so that serializing a list
     * of videos/episodes does not issue a query per item.
     */
    public function hasUnlocked(string $itemId, string $itemType): bool
    {
        // Administrators oversee the full catalogue and can preview every
        // protected title without creating artificial purchase records.
        if ($this->isAdmin()) {
            return true;
        }

        if ($this->relationLoaded('purchases')) {
            return $this->purchases
                ->where('item_id', $itemId)
                ->where('item_type', $itemType)
                ->isNotEmpty();
        }

        return $this->purchases()
            ->where('item_id', $itemId)
            ->where('item_type', $itemType)
            ->exists();
    }

    /**
     * IDs of unlocked single videos, as strings (frontend compares against String(id)).
     *
     * @return list<string>
     */
    public function unlockedVideoIds(): array
    {
        return $this->purchases
            ->where('item_type', 'single')
            ->pluck('item_id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function unlockedSeriesIds(): array
    {
        return $this->purchases
            ->where('item_type', 'series')
            ->pluck('item_id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }
}
