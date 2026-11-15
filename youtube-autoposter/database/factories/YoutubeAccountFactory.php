<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class YoutubeAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'channel_id' => 'UC'.fake()->unique()->regexify('[A-Za-z0-9]{22}'),
            'channel_title' => fake()->company(),
            'refresh_token' => 'refresh-'.fake()->uuid(),
            'status' => 'connected',
            'connected_at' => now(),
        ];
    }

    public function disconnected(): static
    {
        return $this->state(['status' => 'disconnected']);
    }
}
