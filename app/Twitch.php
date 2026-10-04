<?php

namespace App;

use App\Models\BroadcasterToken;
use App\Models\TwitchBan;
use App\Models\TwitchModerator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Twitch
{
    public const HELIX = 'https://api.twitch.tv/helix';

    public const TOKEN_URL = 'https://id.twitch.tv/oauth2/token';

    /**
     * Scopes a broadcaster grants when connecting their channel to this app.
     * - moderation:read        list bans and moderators; channel.moderator.* EventSub
     * - channel:moderate       channel.ban / channel.unban EventSub
     * - channel:manage:broadcast  set the stream title
     */
    public const BROADCASTER_SCOPES = [
        'moderation:read',
        'channel:moderate',
        'channel:manage:broadcast',
    ];

    /**
     * EventSub subscriptions that keep the local ban and moderator lists current.
     */
    public const EVENTSUB_TYPES = [
        'channel.ban',
        'channel.unban',
        'channel.moderator.add',
        'channel.moderator.remove',
    ];

    public static function checkUserSubscription(string $accessToken, string $channelId, string $userId): TwitchSubscription
    {
        $response = Http::withHeaders([
            'Client-ID' => Config::get('services.twitch.client_id'),
            'Authorization' => 'Bearer ' . $accessToken,
        ])->get(self::HELIX . '/subscriptions/user', [
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

    /**
     * @param string $accessToken
     * @param array $channels
     * @param string $userId
     * @return Collection<string, TwitchSubscription>
     */
    public static function checkUserSubscriptions(string $accessToken, array $channels, string $userId): Collection
    {
        $headers = [
            'Client-ID' => config('services.twitch.client_id'),
            'Authorization' => 'Bearer ' . $accessToken,
        ];

        $responses = Http::pool(
            fn(Pool $pool) =>
            collect($channels)->map(
                fn($channel) =>
                $pool
                    ->as($channel)
                    ->withHeaders($headers)
                    ->get(self::HELIX . '/subscriptions/user', [
                        'broadcaster_id' => $channel,
                        'user_id' => $userId,
                    ])
            )
        );

        return collect($responses)->map(function ($response) {
            if ($response->successful()) {
                $data = $response->json();
                if (empty($data['data'])) {
                    return TwitchSubscription::None;
                }

                $subscription = $data['data'][0];
                return TwitchSubscription::tryFrom($subscription['tier']);
            }

            logger()->warning($response->json());
            return TwitchSubscription::None;
        });
    }

    /**
     * App access token (client credentials). EventSub webhook subscriptions require one.
     */
    public static function appAccessToken(): string
    {
        $cached = Cache::get('twitch.app_access_token');
        if ($cached) {
            return $cached;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.twitch.client_id'),
            'client_secret' => config('services.twitch.client_secret'),
            'grant_type' => 'client_credentials',
        ])->throw();

        $token = $response->json('access_token');
        Cache::put('twitch.app_access_token', $token, now()->addSeconds(max(60, $response->json('expires_in') - 300)));

        return $token;
    }

    /**
     * A valid user access token for a connected broadcaster, refreshed if needed.
     */
    public static function broadcasterAccessToken(string $broadcasterId): string
    {
        $token = BroadcasterToken::where('broadcaster_id', $broadcasterId)->first();

        if ($token === null) {
            throw new RuntimeException("Broadcaster {$broadcasterId} has not connected their channel. Visit /twitch/broadcaster/connect while logged in as them.");
        }

        if ($token->isExpired()) {
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'client_id' => config('services.twitch.client_id'),
                'client_secret' => config('services.twitch.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $token->refresh_token,
            ]);

            if ($response->failed()) {
                throw new RuntimeException("Could not refresh the token for broadcaster {$broadcasterId}; they need to reconnect. Twitch said: " . $response->body());
            }

            $token->update([
                'access_token' => $response->json('access_token'),
                'refresh_token' => $response->json('refresh_token'),
                'expires_at' => now()->addSeconds($response->json('expires_in')),
                'scopes' => $response->json('scope') ?? $token->scopes,
            ]);
        }

        return $token->access_token;
    }

    public static function asBroadcaster(string $broadcasterId): PendingRequest
    {
        return Http::withHeaders([
            'Client-ID' => config('services.twitch.client_id'),
        ])->withToken(self::broadcasterAccessToken($broadcasterId))->baseUrl(self::HELIX);
    }

    public static function asApp(): PendingRequest
    {
        return Http::withHeaders([
            'Client-ID' => config('services.twitch.client_id'),
        ])->withToken(self::appAccessToken())->baseUrl(self::HELIX);
    }

    public static function setTitle(string $broadcasterId, string $title): void
    {
        self::asBroadcaster($broadcasterId)
            ->patch('/channels?broadcaster_id=' . urlencode($broadcasterId), ['title' => $title])
            ->throw();
    }

    /**
     * Replace the local ban and moderator lists for a channel with what Twitch reports.
     *
     * @return array{bans: int, moderators: int}
     */
    public static function syncModeration(string $broadcasterId): array
    {
        $bans = self::paginate($broadcasterId, '/moderation/banned');
        $moderators = self::paginate($broadcasterId, '/moderation/moderators');

        DB::transaction(function () use ($broadcasterId, $bans, $moderators) {
            TwitchBan::where('broadcaster_id', $broadcasterId)->delete();
            foreach ($bans as $ban) {
                TwitchBan::create([
                    'broadcaster_id' => $broadcasterId,
                    'twitch_user_id' => $ban['user_id'],
                    'ends_at' => empty($ban['expires_at']) ? null : Carbon::parse($ban['expires_at']),
                ]);
            }

            TwitchModerator::where('broadcaster_id', $broadcasterId)->delete();
            foreach ($moderators as $moderator) {
                TwitchModerator::create([
                    'broadcaster_id' => $broadcasterId,
                    'twitch_user_id' => $moderator['user_id'],
                ]);
            }
        });

        return ['bans' => count($bans), 'moderators' => count($moderators)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function paginate(string $broadcasterId, string $path): array
    {
        $rows = [];
        $cursor = null;

        do {
            $query = array_filter([
                'broadcaster_id' => $broadcasterId,
                'first' => 100,
                'after' => $cursor,
            ]);

            $response = self::asBroadcaster($broadcasterId)->get($path, $query)->throw();
            array_push($rows, ...$response->json('data', []));
            $cursor = $response->json('pagination.cursor');
        } while ($cursor);

        return $rows;
    }

    /**
     * Create a webhook EventSub subscription for one of EVENTSUB_TYPES.
     */
    public static function subscribeEventSub(string $broadcasterId, string $type): void
    {
        if (! in_array($type, self::EVENTSUB_TYPES, true)) {
            throw new RuntimeException("Unsupported EventSub type {$type}");
        }

        $response = self::asApp()->post('/eventsub/subscriptions', [
            'type' => $type,
            'version' => '1',
            'condition' => ['broadcaster_user_id' => $broadcasterId],
            'transport' => [
                'method' => 'webhook',
                'callback' => config('services.twitch.eventsub_callback') ?: route('twitch.eventsub'),
                'secret' => config('services.twitch.eventsub_secret'),
            ],
        ]);

        // 409 means the subscription already exists, which is the state we want.
        if ($response->status() !== 409) {
            $response->throw();
        }
    }
}
