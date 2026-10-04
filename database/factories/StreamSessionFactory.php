<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StreamSession>
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
