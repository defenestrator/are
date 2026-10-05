<?php

namespace Database\Factories;

use App\IdentityProvider;
use App\Models\Identity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Attach identities through the user factory (`User::factory()->twitch('123')`)
 * or with `->for($user)`. Every user already gets a Twitch identity by default.
 *
 * @extends Factory<Identity>
 */
class IdentityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => IdentityProvider::Twitch,
            'provider_user_id' => (string) fake()->unique()->randomNumber(8, true),
            'name' => fake()->userName(),
            'avatar_url' => 'https://static-cdn.jtvnw.net/jtv_user_pictures/0744a2a3-109a-4e48-85b1-285221fdeefc-profile_image-300x300.png',
        ];
    }

    public function provider(IdentityProvider $provider, ?string $providerUserId = null): static
    {
        return $this->state(fn () => array_filter([
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
        ]));
    }
}
