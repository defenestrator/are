<?php

namespace App\Listeners;

use App\Enums\SongRequestSource;
use App\Events\Twitch\RewardRedeemed;
use App\Exceptions\SongRequestRejected;
use App\Identities;
use App\IdentityProvider;
use App\Models\SongRequest;
use App\Models\TwitchBan;
use App\Models\User;
use App\SongRequests;

/**
 * Turns redemptions of the configured song-request reward
 * (music.song_request_reward_id) into song requests, using the redeemer's
 * text as the song. It runs inside the queued redemption job, so it is not
 * queued again. RewardRedeemed fires once per new redemption, and each
 * redemption makes at most one request.
 *
 * A refused redemption is logged, not refunded: refunding means updating the
 * redemption through Helix, which is a follow-up.
 */
class QueueSongFromRedemption
{
    public function handle(RewardRedeemed $event): void
    {
        $redemption = $event->redemption;
        $rewardId = (string) config('music.song_request_reward_id');

        if ($rewardId === '' || ! hash_equals($rewardId, $redemption->reward_id)) {
            return;
        }

        if (SongRequest::where('channel_point_redemption_id', $redemption->id)->exists()) {
            return;
        }

        $user = Identities::findUser(IdentityProvider::Twitch, $redemption->twitch_user_id);

        try {
            // A linked user's bans are checked by SongRequests::request(); an
            // unlinked redeemer can still carry a Twitch ban here.
            if ($user === null && TwitchBan::inEffect()
                ->where('twitch_user_id', $redemption->twitch_user_id)
                ->whereIn('broadcaster_id', User::getBroadcasterIDs())
                ->exists()) {
                throw SongRequestRejected::banned();
            }

            $track = SongRequests::resolve((string) $redemption->user_input);
            SongRequests::request(
                $track,
                $user,
                $redemption->user_name,
                SongRequestSource::ChannelPoints,
                $redemption,
            );
        } catch (SongRequestRejected $e) {
            logger()->info('Channel-point song request refused', [
                'redemption_id' => $redemption->twitch_redemption_id,
                'twitch_user_id' => $redemption->twitch_user_id,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
