<?php

namespace Database\Factories;

use App\Enums\VideoStatus;
use App\Models\YoutubeAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'topic' => fake()->sentence(6),
            'angle' => fake()->sentence(12),
            'status' => VideoStatus::Draft,
            'category_id' => '27',
            'is_short' => false,
        ];
    }

    public function queued(): static
    {
        return $this->state(fn () => [
            'youtube_account_id' => YoutubeAccount::factory(),
            'status' => VideoStatus::Queued,
            'title' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'tags' => ['finance', 'south africa'],
            'file_path' => 'videos/test/final.mp4',
            'publish_at' => now()->addHours(3),
            'approved_at' => now(),
        ]);
    }
}
