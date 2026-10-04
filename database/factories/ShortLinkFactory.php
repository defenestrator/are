<?php

namespace Database\Factories;

use App\Models\ShortLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShortLink>
 */
class ShortLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => ShortLink::generateCode(),
            'destination' => '/about#work-with-us',
            'utm_source' => 'twitch',
            'utm_medium' => 'stream',
            'utm_campaign' => fake()->date('Y-m-d').'-'.fake()->slug(2),
            'utm_content' => null,
        ];
    }
}
