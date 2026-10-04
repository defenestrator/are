<?php

namespace Database\Factories;

use App\Clips\ClipDecisionKind;
use App\Models\ClipDecision;
use App\Models\StreamMarker;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClipDecision>
 */
class ClipDecisionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stream_marker_id' => StreamMarker::factory()->ready(),
            'user_id' => User::factory(),
            'decision' => ClipDecisionKind::Approved,
        ];
    }
}
