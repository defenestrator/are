<?php

namespace Database\Factories;

use App\Clips\StreamMarkerStatus;
use App\Models\StreamMarker;
use App\Models\StreamSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StreamMarker>
 */
class StreamMarkerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stream_session_id' => StreamSession::factory(),
            'broadcaster_id' => '1000',
            'twitch_marker_id' => (string) Str::uuid(),
            'position_seconds' => fake()->numberBetween(120, 7200),
            'description' => fake()->sentence(4),
            'status' => StreamMarkerStatus::ClipPending,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => StreamMarkerStatus::ClipReady,
            'vod_id' => '555',
            'clip_id' => 'FiveWordsForClipSlug'.fake()->unique()->numberBetween(1, 99999),
            'clip_edit_url' => 'https://www.twitch.tv/edos/clip/FiveWordsForClipSlug',
            'clip_requested_at' => now()->subMinute(),
            'landscape_download_url' => 'https://production.assets.clips.twitchcdn.net/landscape.mp4',
            'portrait_download_url' => null,
            'download_urls_expire_at' => now()->addMinutes(30),
        ]);
    }

    public function markerFailed(string $error = 'Twitch would not add a marker.'): static
    {
        return $this->state(fn () => [
            'status' => StreamMarkerStatus::MarkerFailed,
            'twitch_marker_id' => null,
            'position_seconds' => null,
            'error' => $error,
        ]);
    }
}
