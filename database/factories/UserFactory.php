<?php

namespace Database\Factories;

use App\IdentityProvider;
use App\Models\Identity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
        ];
    }

    /**
     * Every user signs in through at least one identity. A user created without
     * an explicit one gets a Twitch identity, as most viewers have.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if ($user->identities()->doesntExist()) {
                Identity::factory()->for($user)->create();
            }
        });
    }

    public function withIdentity(IdentityProvider $provider, ?string $providerUserId = null): static
    {
        return $this->has(Identity::factory()->provider($provider, $providerUserId), 'identities');
    }

    public function twitch(?string $twitchId = null): static
    {
        return $this->withIdentity(IdentityProvider::Twitch, $twitchId);
    }

    public function facebook(?string $facebookId = null): static
    {
        return $this->withIdentity(IdentityProvider::Facebook, $facebookId);
    }

    public function youtube(?string $channelId = null): static
    {
        return $this->withIdentity(IdentityProvider::YouTube, $channelId);
    }
}
