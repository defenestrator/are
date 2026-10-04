<?php

namespace Database\Factories;

use App\Enums\SongRequestSource;
use App\Enums\SongRequestStatus;
use App\Models\SongRequest;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SongRequest>
 */
class SongRequestFactory extends Factory
{
    /**
     * A queued chat request for a stream-safe track.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'track_id' => Track::factory()->streamSafe(),
            'requester_id' => User::factory(),
            'requester_name' => fake()->userName(),
            'source' => SongRequestSource::Chat,
            'status' => SongRequestStatus::Queued,
        ];
    }

    public function playing(): static
    {
        return $this->state(['status' => SongRequestStatus::Playing, 'started_at' => now()]);
    }

    public function played(): static
    {
        return $this->state(['status' => SongRequestStatus::Played, 'started_at' => now()->subMinutes(4), 'finished_at' => now()]);
    }
}
