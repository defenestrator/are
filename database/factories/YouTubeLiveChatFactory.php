<?php

namespace Database\Factories;

use App\Models\YouTubeLiveChat;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<YouTubeLiveChat>
 */
class YouTubeLiveChatFactory extends Factory
{
    protected $model = YouTubeLiveChat::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_id' => Str::random(11),
            'channel_id' => 'UC'.Str::random(22),
            'live_chat_id' => 'Cg0KC'.Str::random(20),
            'title' => fake()->sentence(4),
            'status' => YouTubeLiveChat::POLLING,
            'next_page_token' => null,
            'poll_interval_ms' => 3000,
            'next_poll_at' => now(),
            'consecutive_errors' => 0,
            'started_at' => now()->subMinute(),
        ];
    }
}
