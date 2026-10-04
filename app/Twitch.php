<?php

namespace App;

use App\Models\BroadcasterToken;
use App\Models\TwitchBan;
use App\Models\TwitchModerator;
use App\Models\User;
use App\Models\UserTwitchSubscription;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class Twitch
{
    public const HELIX = 'https://api.twitch.tv/helix';

    public const TOKEN_URL = 'https://id.twitch.tv/oauth2/token';

    /** Seconds. Sign-in waits on these lookups, so they stay short. */
    private const SUBSCRIPTION_CONNECT_TIMEOUT = 3;

    private const SUBSCRIPTION_TIMEOUT = 5;

    /**
     * Scopes a broadcaster grants when connecting their channel to this app.
     * - moderation:read        list bans and moderators; channel.moderator.* EventSub
     * - channel:moderate       channel.ban / channel.unban EventSub
     * - channel:manage:broadcast  set the stream title
     * - user:read:chat, user:bot, channel:bot  channel.chat.message, read as the broadcaster
     * - channel:read:redemptions  channel.channel_points_custom_reward_redemption.add
     * - channel:read:subscriptions  channel.subscribe, channel.subscription.end
     * - moderator:read:followers  channel.follow v2, with the broadcaster as moderator
     * channel.raid, stream.online and stream.offline need no scope.
     *
     * @see https://dev.twitch.tv/docs/eventsub/eventsub-subscription-types/
     */
    public const BROADCASTER_SCOPES = [
        'moderation:read',
        'channel:moderate',
        'channel:manage:broadcast',
        'user:read:chat',
        'user:bot',
        'channel:bot',
        'channel:read:redemptions',
        'channel:read:subscriptions',
        'moderator:read:followers',
    ];

    /**
     * EventSub subscriptions this app creates for every channel it serves.
     * The ban and moderator types keep the local lists current; the rest are
     * handed to queued jobs in App\Jobs\EventSub.
     */
    public const EVENTSUB_TYPES = [
        'channel.ban',
        'channel.unban',
        'channel.moderator.add',
        'channel.moderator.remove',
        'channel.chat.message',
        'channel.channel_points_custom_reward_redemption.add',
        'channel.subscribe',
        'channel.subscription.end',
        'channel.raid',
        'channel.follow',
        'stream.online',
        'stream.offline',
    ];

    public static function checkUserSubscription(string $accessToken, string $channelId, string $userId): TwitchSubscription
    {
        $response = Http::withHeaders([
            'Client-ID' => Config::get('services.twitch.client_id'),
            'Authorization' => 'Bearer '.$accessToken,
        ])->get(self::HELIX.'/subscriptions/user', [
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
     * The viewer's tier on each channel. A channel whose lookup failed (no
     * connection, a timeout, a 5xx, a rate limit or a rejected token) maps to
     * null, meaning unknown, never to None: a Helix outage must not read as
     * "not subscribed". Helix answers 404 for "not subscribed", which is None.
     *
     * Each lookup times out quickly and retries once on a connection failure
     * or a 5xx, so a slow channel cannot hold up sign-in for long.
     *
     * @param  list<string>  $channels
     * @return Collection<string, TwitchSubscription|null>
     */
    public static function checkUserSubscriptions(string $accessToken, array $channels, string $userId): Collection
    {
        $headers = [
            'Client-ID' => config('services.twitch.client_id'),
            'Authorization' => 'Bearer '.$accessToken,
        ];

        $responses = Http::pool(
            fn (Pool $pool) => collect($channels)->map(
                fn ($channel) => $pool
                    ->as($channel)
                    ->withHeaders($headers)
                    ->connectTimeout(self::SUBSCRIPTION_CONNECT_TIMEOUT)
                    ->timeout(self::SUBSCRIPTION_TIMEOUT)
                    ->retry(2, 200, fn (Throwable $e) => ! $e instanceof RequestException || $e->response->serverError(), throw: false)
                    ->get(self::HELIX.'/subscriptions/user', [
                        'broadcaster_id' => $channel,
                        'user_id' => $userId,
                    ])
            )
        );

        return collect($responses)->map(function ($response, $channel) use ($userId) {
            if ($response instanceof Response && $response->successful()) {
                $tier = $response->json('data.0.tier');

                return $tier === null ? TwitchSubscription::None : (TwitchSubscription::tryFrom($tier) ?? TwitchSubscription::None);
            }

            if ($response instanceof Response && $response->notFound()) {
                return TwitchSubscription::None;
            }

            // Never log the response body or headers: the request carried the viewer's token.
            Log::warning('Twitch subscription lookup failed; treating the tier as unknown.', [
                'broadcaster_id' => (string) $channel,
                'twitch_user_id' => $userId,
                'reason' => $response instanceof Response ? 'HTTP '.$response->status() : $response::class,
            ]);

            return null;
        });
    }

    /**
     * Look up the viewer's tiers and store the ones Helix answered. Rows for
     * channels whose lookup failed are left as they were, not overwritten.
     *
     * @return list<string> the channels whose tier is still unknown
     */
    public static function syncUserSubscriptions(User $user, string $accessToken, string $twitchUserId): array
    {
        $unknown = [];

        foreach (self::checkUserSubscriptions($accessToken, User::getAllFriendIDs(), $twitchUserId) as $broadcasterId => $tier) {
            if ($tier === null) {
                $unknown[] = (string) $broadcasterId;

                continue;
            }

            UserTwitchSubscription::updateOrCreate(
                ['user_id' => $user->id, 'broadcaster_id' => (string) $broadcasterId],
                ['twitch_subscription' => $tier],
            );
        }

        return $unknown;
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
                throw new RuntimeException("Could not refresh the token for broadcaster {$broadcasterId}; they need to reconnect. Twitch said: ".$response->body());
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
            ->patch('/channels?broadcaster_id='.urlencode($broadcasterId), ['title' => $title])
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

        [$version, $condition] = self::eventSubDefinition($type, $broadcasterId);

        $response = self::asApp()->post('/eventsub/subscriptions', [
            'type' => $type,
            'version' => $version,
            'condition' => $condition,
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

    /**
     * The version and condition Twitch expects for each of EVENTSUB_TYPES.
     *
     * @return array{0: string, 1: array<string, string>}
     *
     * @see https://dev.twitch.tv/docs/eventsub/eventsub-subscription-types/
     */
    public static function eventSubDefinition(string $type, string $broadcasterId): array
    {
        return match ($type) {
            // Read chat as the broadcaster, so their own grant covers user:read:chat and user:bot.
            'channel.chat.message' => ['1', ['broadcaster_user_id' => $broadcasterId, 'user_id' => $broadcasterId]],
            // Incoming raids only.
            'channel.raid' => ['1', ['to_broadcaster_user_id' => $broadcasterId]],
            // v2 needs a moderator; the broadcaster moderates their own channel.
            'channel.follow' => ['2', ['broadcaster_user_id' => $broadcasterId, 'moderator_user_id' => $broadcasterId]],
            default => ['1', ['broadcaster_user_id' => $broadcasterId]],
        };
    }
}
