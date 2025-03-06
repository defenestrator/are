<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
<<<<<<< HEAD
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
=======
>>>>>>> origin/master

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
<<<<<<< HEAD
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
=======

        // TOOD: This doesn't actually create the right stuff. We will need to do that later.
        return [
            'name' => fake()->name(),
            'twitch_id' => fake()->unique()->randomNumber(8),
            'twitch_avatar_url' => "https://static-cdn.jtvnw.net/jtv_user_pictures/0744a2a3-109a-4e48-85b1-285221fdeefc-profile_image-300x300.png",
        ];
    }
>>>>>>> origin/master
}
