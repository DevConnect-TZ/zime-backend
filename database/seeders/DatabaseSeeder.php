<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Services\Payments\PaymentService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $adminEmail = Str::lower((string) (config('services.platform.bootstrap_admin_email') ?: 'admin@zime.app'));

        User::query()->firstOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Platform Admin',
                'role' => User::ROLE_ADMIN,
                'status' => User::STATUS_ACTIVE,
                'firebase_uid' => null,
            ]
        );

        Setting::put(PaymentService::SETTING_ACTIVE_GATEWAY, 'sonicpesa');

        $this->seedCatalogue();
    }

    private function seedCatalogue(): void
    {
        if (Video::query()->exists()) {
            return;
        }

        $movies = [
            ['title' => 'The Dark Horizon', 'genre' => 'Sci-Fi', 'rating' => 'PG-13', 'price' => 5000, 'trending' => true],
            ['title' => "Ocean's Whisper", 'genre' => 'Documentary', 'rating' => 'G', 'price' => 3000, 'trending' => true],
            ['title' => 'Midnight Chase', 'genre' => 'Action', 'rating' => 'R', 'price' => 8000, 'popular' => true],
            ['title' => 'The Lost Kingdom', 'genre' => 'Fantasy', 'rating' => 'PG-13', 'price' => 10000, 'popular' => true],
            ['title' => 'Echoes of War', 'genre' => 'Drama', 'rating' => 'R', 'price' => 4000],
            ['title' => 'Laugh Factory', 'genre' => 'Comedy', 'rating' => 'PG-13', 'price' => 2000],
        ];

        foreach ($movies as $movie) {
            Video::factory()
                ->when($movie['trending'] ?? false, fn ($f) => $f->trending())
                ->when($movie['popular'] ?? false, fn ($f) => $f->popular())
                ->create([
                    'title' => $movie['title'],
                    'genre' => $movie['genre'],
                    'rating' => $movie['rating'],
                    'price' => $movie['price'],
                    'type' => 'single',
                ]);
        }

        $shadow = Video::factory()->series()->create([
            'title' => 'Shadow Protocol',
            'genre' => 'Thriller',
            'rating' => 'TV-MA',
            'price' => 20000,
        ]);

        $shadow->episodes()->createMany([
            ['title' => 'The Beginning', 'season' => 1, 'episode' => 1, 'duration' => '45m', 'video_url' => 'https://www.w3schools.com/html/mov_bbb.mp4'],
            ['title' => 'Deep Cover', 'season' => 1, 'episode' => 2, 'duration' => '42m', 'video_url' => 'https://www.w3schools.com/html/mov_bbb.mp4'],
            ['title' => 'Double Agent', 'season' => 2, 'episode' => 1, 'duration' => '46m', 'video_url' => 'https://www.w3schools.com/html/mov_bbb.mp4'],
        ]);
    }
}
