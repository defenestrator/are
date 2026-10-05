<?php

namespace Database\Factories;

use App\Models\StreamSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StreamSession>
 */
class StreamSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'broadcaster_id' => '1000',
            'twitch_stream_id' => (string) fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'type' => 'live',
            'started_at' => now()->subHour(),
            'ended_at' => null,
        ];
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes) => ['ended_at' => now()]);
    }
}
