<?php

namespace Database\Factories;

use App\TwitchSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {

        // TOOD: This doesn't actually create the right stuff. We will need to do that later.
        return [
            'name' => fake()->name(),
            'twitch_id' => fake()->unique()->randomNumber(8),
            'twitch_access_token' => fake()->unique()->sha256(),
            'twitch_refresh_token' => fake()->unique()->sha256(),
            'twitch_expires_in' => fake()->unique()->randomNumber(4),
            'twitch_avatar_url' => fake()->unique()->imageUrl(),
            'twitch_subscription' => TwitchSubscription::Tier1,
        ];

    }
}
