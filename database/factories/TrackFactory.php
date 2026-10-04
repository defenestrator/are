<?php

namespace Database\Factories;

use App\Enums\ContentIdStatus;
use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Track>
 */
class TrackFactory extends Factory
{
    /**
     * Define the model's default state: an unregistered track that is not
     * yet in the stream-safe pack.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->words(3, true),
            'artist' => fake()->name(),
            'file_path' => 'tracks/'.fake()->uuid().'.mp3',
            'stems_path' => null,
            'content_id_status' => ContentIdStatus::NotRegistered,
            'stream_safe' => false,
            'duration_seconds' => fake()->numberBetween(90, 420),
            'attribution' => null,
        ];
    }

    public function streamSafe(): static
    {
        return $this->state(['stream_safe' => true]);
    }

    public function registered(): static
    {
        return $this->state(['content_id_status' => ContentIdStatus::Registered, 'stream_safe' => false]);
    }

    public function allowListed(): static
    {
        return $this->state(['content_id_status' => ContentIdStatus::AllowListed]);
    }

    public function withStems(): static
    {
        return $this->state(['stems_path' => 'stems/'.fake()->uuid().'.zip']);
    }
}
