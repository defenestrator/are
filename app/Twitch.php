<?php
namespace App;

use Illuminate\Support\Facades\Http;

class Twitch {
    public static function checkUserSubscription(string $accessToken, string $channelId, string $userId): TwitchSubscription {
        $response = Http::withHeaders([
            'Client-ID' => env('TWITCH_CLIENT_ID'),
            'Authorization' => 'Bearer ' . $accessToken,
        ])->get('https://api.twitch.tv/helix/subscriptions/user', [
            'broadcaster_id' => $channelId,
            'user_id' => $userId,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            if (empty($data['data'])) {
                return TwitchSubscription::None;
            }

            $subscription = $data['data'][0];
            return TwitchSubscription::tryFrom($subscription['tier']);
        }

        return TwitchSubscription::None;
    }
}
