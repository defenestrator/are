<?php

namespace Database\Factories;

use App\Enums\Overlay;
use App\Models\OverlayToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OverlayToken>
 */
class OverlayTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'overlay' => fake()->randomElement(Overlay::cases()),
            'token_hash' => OverlayToken::hash(fake()->sha256()),
        ];
    }

    /**
     * A token for the given overlay whose plaintext the test knows.
     */
    public function withToken(Overlay $overlay, string $token): static
    {
        return $this->state([
            'overlay' => $overlay,
            'token_hash' => OverlayToken::hash($token),
        ]);
    }
}
