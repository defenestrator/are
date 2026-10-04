<?php

namespace App\Jobs\EventSub;

use App\Events\Twitch\RewardRedeemed;
use App\Models\ChannelPointRedemption;
use Illuminate\Support\Carbon;

/**
 * channel.channel_points_custom_reward_redemption.add: store the redemption
 * for the Chat Control Bus and the VTuber bridge.
 */
class HandleChannelPointRedemption extends EventSubJob
{
    protected function process(): void
    {
        $reward = (array) ($this->event['reward'] ?? []);

        $redemption = ChannelPointRedemption::firstOrCreate(
            ['twitch_redemption_id' => $this->string('id')],
            [
                'broadcaster_id' => $this->string('broadcaster_user_id'),
                'twitch_user_id' => $this->string('user_id'),
                'user_login' => $this->string('user_login'),
                'user_name' => $this->string('user_name'),
                'reward_id' => (string) ($reward['id'] ?? ''),
                'reward_title' => (string) ($reward['title'] ?? ''),
                'reward_cost' => (int) ($reward['cost'] ?? 0),
                'reward_prompt' => ($reward['prompt'] ?? '') === '' ? null : $reward['prompt'],
                'user_input' => $this->string('user_input') === '' ? null : $this->string('user_input'),
                'status' => $this->string('status') ?: 'unfulfilled',
                'redeemed_at' => empty($this->event['redeemed_at']) ? $this->sentAt() : Carbon::parse($this->event['redeemed_at']),
            ],
        );

        if ($redemption->wasRecentlyCreated) {
            RewardRedeemed::dispatch($redemption);
        }
    }
}
