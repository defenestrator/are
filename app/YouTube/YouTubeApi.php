<?php

namespace App\YouTube;

use App\Models\YouTubeChannelToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The few YouTube Data API calls ARE makes, each one charged to Quota whether
 * it succeeds or not.
 *
 * Reading uses an API key, sent in the X-Goog-Api-Key header rather than
 * ?key=, so it never appears in a URL that an exception, a log line or Sentry
 * might record. Posting needs the channel owner's OAuth token (an API key
 * cannot post), granted at /youtube/broadcaster/connect.
 */
class YouTubeApi
{
    public const BASE = 'https://www.googleapis.com/youtube/v3';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** Needed by liveChatMessages.insert; youtube.readonly is not enough. */
    public const POST_SCOPE = 'https://www.googleapis.com/auth/youtube.force-ssl';

    /**
     * Asked for alongside POST_SCOPE for YouTube Analytics (#12), which reads
     * reports.query with these stored tokens (ids=channel==MINE). reports.query
     * has required youtube.readonly since 2018, and whether force-ssl covers
     * it is unverified, so both are requested explicitly.
     *
     * @var list<string>
     */
    public const ANALYTICS_SCOPES = [
        'https://www.googleapis.com/auth/youtube.readonly',
        'https://www.googleapis.com/auth/yt-analytics.readonly',
    ];

    /** YouTube refuses chat messages longer than this. */
    public const MAX_MESSAGE_LENGTH = 200;

    /**
     * videos.list for up to 50 ids in one call (1 unit): each video's channel,
     * title and live chat id.
     *
     * @param  list<string>  $videoIds
     *
     * @see https://developers.google.com/youtube/v3/docs/videos/list
     */
    public static function videos(array $videoIds): Response
    {
        $http = self::withKey(); // a missing key throws here, before anything is charged

        return self::send('videos.list', fn () => $http->get('/videos', [
            'part' => 'snippet,liveStreamingDetails',
            'id' => implode(',', $videoIds),
            'maxResults' => 50,
        ]));
    }

    /**
     * liveChatMessages.list (1 unit): messages after $pageToken.
     *
     * @see https://developers.google.com/youtube/v3/live/docs/liveChatMessages/list
     */
    public static function liveChatMessages(string $liveChatId, ?string $pageToken): Response
    {
        $http = self::withKey();

        return self::send('liveChatMessages.list', fn () => $http->get('/liveChat/messages', array_filter([
            'liveChatId' => $liveChatId,
            'part' => 'snippet,authorDetails',
            'maxResults' => 2000,
            'pageToken' => $pageToken,
        ])));
    }

    /**
     * channels.list?mine=true (1 unit): the channel an OAuth token belongs to.
     *
     * @see https://developers.google.com/youtube/v3/docs/channels/list
     */
    public static function myChannel(string $accessToken): Response
    {
        return self::send('channels.list', fn () => self::base()->withToken($accessToken)->get('/channels', [
            'part' => 'id,snippet',
            'mine' => 'true',
        ]));
    }

    /**
     * liveChatMessages.insert (50 units): post a message as the channel owner.
     *
     * The 50 units are reserved first, in one conditional update that keeps
     * the day's units at or under $quotaCeiling. Returns null, without
     * sending anything, when that reservation is refused.
     *
     * @see https://developers.google.com/youtube/v3/live/docs/liveChatMessages/insert
     */
    public static function insertChatMessage(string $channelId, string $liveChatId, string $text, int $quotaCeiling): ?Response
    {
        $token = self::accessTokenFor($channelId);

        if (! Quota::reserve('liveChatMessages.insert', $quotaCeiling)) {
            return null;
        }

        try {
            $response = self::base()
                ->withToken($token)
                ->withQueryParameters(['part' => 'snippet'])
                ->post('/liveChat/messages', [
                    'snippet' => [
                        'liveChatId' => $liveChatId,
                        'type' => 'textMessageEvent',
                        'textMessageDetails' => ['messageText' => mb_substr($text, 0, self::MAX_MESSAGE_LENGTH)],
                    ],
                ]);
        } catch (Throwable $e) {
            Quota::recordFailure('liveChatMessages.insert');

            throw $e;
        }

        if ($response->failed()) {
            Quota::recordFailure('liveChatMessages.insert');
        }

        return $response;
    }

    /**
     * Whether a connection failure happened before the request reached the
     * server (DNS, refused or timed-out connect), so retrying cannot repeat
     * it. A read timeout is not: Google may already have acted (#126 review).
     */
    public static function neverReachedServer(ConnectionException $e): bool
    {
        $message = $e->getMessage();

        return (bool) preg_match('/cURL error (6|7):/', $message)
            || str_contains($message, 'Connection timed out')
            || str_contains($message, 'Failed to connect')
            || str_contains($message, 'Could not resolve host');
    }

    /**
     * A valid access token for a connected channel, refreshed if it is about
     * to expire. Throws if the channel is not connected or the refresh is
     * refused (revoked, or a 7-day Testing-mode token that has lapsed).
     */
    public static function accessTokenFor(string $channelId): string
    {
        $token = YouTubeChannelToken::where('channel_id', $channelId)->first();

        if ($token === null) {
            throw new RuntimeException("YouTube channel {$channelId} is not connected. Its owner must visit /youtube/broadcaster/connect.");
        }

        if ($token->isExpired()) {
            $response = Http::asForm()->connectTimeout(5)->timeout(10)->post(self::TOKEN_URL, [
                'client_id' => config('services.youtube.oauth.client_id'),
                'client_secret' => config('services.youtube.oauth.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $token->refresh_token,
            ]);

            if ($response->failed()) {
                // Only Google's error code: the body is safe, but never log the request.
                throw new RuntimeException("Could not refresh the YouTube token for channel {$channelId}; its owner must reconnect at /youtube/broadcaster/connect. Google said: ".$response->json('error', 'HTTP '.$response->status()));
            }

            $token->update([
                'access_token' => $response->json('access_token'),
                // Google sends a new refresh token only sometimes; keep the old one otherwise.
                'refresh_token' => $response->json('refresh_token') ?: $token->refresh_token,
                'expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
                'scopes' => is_string($response->json('scope')) ? explode(' ', $response->json('scope')) : $token->scopes,
            ]);
        }

        return $token->access_token;
    }

    /**
     * The first error reason in a failed response, such as "rateLimitExceeded".
     */
    public static function errorReason(Response $response): ?string
    {
        $reason = $response->json('error.errors.0.reason');

        return is_string($reason) ? $reason : null;
    }

    private static function base(): PendingRequest
    {
        return Http::baseUrl(self::BASE)->acceptJson()->connectTimeout(5)->timeout(10);
    }

    private static function withKey(): PendingRequest
    {
        $key = config('services.youtube.api_key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('YOUTUBE_API_KEY is not set.');
        }

        return self::base()->withHeaders(['X-Goog-Api-Key' => $key]);
    }

    /**
     * @param  callable(): Response  $request
     */
    private static function send(string $method, callable $request): Response
    {
        $failed = true;

        try {
            $response = $request();
            $failed = $response->failed();

            return $response;
        } finally {
            // Charged even when the request threw: Google bills invalid requests too.
            Quota::charge($method, $failed);
        }
    }
}
