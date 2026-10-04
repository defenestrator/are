<?php

namespace Database\Factories;

use App\Models\ShortLink;
use App\Models\ShortLinkClick;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShortLinkClick>
 */
class ShortLinkClickFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'short_link_id' => ShortLink::factory(),
            'clicked_at' => now(),
        ];
    }
}
