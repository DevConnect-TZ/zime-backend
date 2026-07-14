<?php

namespace Database\Factories;

use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->catchPhrase();

        return [
            'title' => $title,
            'description' => fake()->paragraph(),
            'type' => 'single',
            'price' => fake()->randomElement([2000, 3000, 5000, 8000, 10000]),
            'thumbnail' => 'https://picsum.photos/seed/'.fake()->uuid().'/400/250',
            'trailer_url' => 'https://www.w3schools.com/html/mov_bbb.mp4',
            'video_link' => 'https://www.w3schools.com/html/mov_bbb.mp4',
            'genre' => fake()->randomElement(['Sci-Fi', 'Drama', 'Action', 'Comedy', 'Documentary']),
            'rating' => fake()->randomElement(['G', 'PG-13', 'R', 'TV-MA']),
            'duration' => fake()->numberBetween(1, 2).'h '.fake()->numberBetween(0, 59).'m',
            'year' => fake()->numberBetween(2020, 2026),
            'is_trending' => false,
            'is_popular' => false,
            'views' => fake()->numberBetween(0, 5000),
        ];
    }

    public function series(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'series',
            'price' => fake()->randomElement([15000, 20000, 25000]),
        ]);
    }

    public function trending(): static
    {
        return $this->state(fn (array $attributes) => ['is_trending' => true]);
    }

    public function popular(): static
    {
        return $this->state(fn (array $attributes) => ['is_popular' => true]);
    }
}
