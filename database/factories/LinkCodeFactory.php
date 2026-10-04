<?php

namespace Database\Factories;

use App\Models\LinkCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * For a code you can type, use LinkCode::issueFor($user), which returns it.
 *
 * @extends Factory<LinkCode>
 */
class LinkCodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code_hash' => LinkCode::hash(Str::random(8)),
            'expires_at' => now()->addMinutes(LinkCode::MINUTES),
        ];
    }
}
