<?php

namespace App\Events\Twitch;

use App\Models\ChannelPointRedemption;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A channel-point reward was redeemed and stored.
 */
class RewardRedeemed
{
    use Dispatchable, SerializesModels;

    public function __construct(public ChannelPointRedemption $redemption) {}
}
