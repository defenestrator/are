<?php

namespace App\YouTube;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The few YouTube Data API calls ARE makes, read with an API key, each one
 * charged to Quota whether it succeeds or not.
 *
 * The key travels in the X-Goog-Api-Key header rather than ?key=, so it never
 * appears in a URL that an exception, a log line or Sentry might record.
 */
class YouTubeApi
{
    public const BASE = 'https://www.googleapis.com/youtube/v3';

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
        return self::get('videos.list', '/videos', [
            'part' => 'snippet,liveStreamingDetails',
            'id' => implode(',', $videoIds),
            'maxResults' => 50,
        ]);
    }

    /**
     * liveChatMessages.list (1 unit): messages after $pageToken.
     *
     * @see https://developers.google.com/youtube/v3/live/docs/liveChatMessages/list
     */
    public static function liveChatMessages(string $liveChatId, ?string $pageToken): Response
    {
        return self::get('liveChatMessages.list', '/liveChat/messages', array_filter([
            'liveChatId' => $liveChatId,
            'part' => 'snippet,authorDetails',
            'maxResults' => 2000,
            'pageToken' => $pageToken,
        ]));
    }

    /**
     * The first error reason in a failed response, such as "rateLimitExceeded".
     */
    public static function errorReason(Response $response): ?string
    {
        $reason = $response->json('error.errors.0.reason');

        return is_string($reason) ? $reason : null;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function get(string $method, string $path, array $query): Response
    {
        $key = config('services.youtube.api_key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('YOUTUBE_API_KEY is not set.');
        }

        $failed = true;

        try {
            $response = Http::baseUrl(self::BASE)
                ->withHeaders(['X-Goog-Api-Key' => $key])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->get($path, $query);

            $failed = $response->failed();

            return $response;
        } finally {
            // Charged even when the request threw: Google bills invalid requests too.
            Quota::charge($method, $failed);
        }
    }
}
