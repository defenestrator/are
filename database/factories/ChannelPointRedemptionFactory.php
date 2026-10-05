<?php

namespace Database\Factories;

use App\Models\ChannelPointRedemption;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChannelPointRedemption>
 */
class ChannelPointRedemptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->userName();

        return [
            'twitch_redemption_id' => (string) Str::uuid(),
            'broadcaster_id' => '1000',
            'twitch_user_id' => (string) fake()->numberBetween(10_000, 99_999),
            'user_login' => strtolower($name),
            'user_name' => $name,
            'reward_id' => (string) Str::uuid(),
            'reward_title' => fake()->words(3, true),
            'reward_cost' => fake()->numberBetween(100, 10_000),
            'reward_prompt' => fake()->sentence(),
            'user_input' => null,
            'status' => 'unfulfilled',
            'redeemed_at' => now(),
        ];
    }
}
