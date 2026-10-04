<?php

namespace App\Clips;

use App\Twitch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The Helix calls behind !clip, all made with the broadcaster's stored token:
 * moderators are not channel editors, so their own tokens cannot do this.
 *
 * Every call has short timeouts and retries once on a connection failure or a
 * 5xx. Other answers (4xx, 429) come back as the Response for the caller to
 * read; nothing here throws on them.
 *
 * @see https://dev.twitch.tv/docs/api/reference/#create-stream-marker
 * @see https://dev.twitch.tv/docs/api/reference/#create-clip-from-vod
 * @see https://dev.twitch.tv/docs/api/reference/#get-clips
 * @see https://dev.twitch.tv/docs/api/reference/#get-clips-download
 */
class ClipHelix
{
    /** Create Clip From VOD and Get Clips Download need this; markers need channel:manage:broadcast. */
    public const CLIPS_SCOPE = 'channel:manage:clips';

    private const CONNECT_TIMEOUT = 3;

    private const TIMEOUT = 10;

    /** POST /streams/markers. user_id and description go in the JSON body. */
    public function createMarker(string $broadcasterId, string $description): Response
    {
        return $this->request($broadcasterId)->post('/streams/markers', array_filter([
            'user_id' => $broadcasterId,
            'description' => $description,
        ], fn ($value) => $value !== ''));
    }

    /**
     * GET /videos for the channel's most recent past broadcasts. While the
     * channel is live (with Store past broadcasts on), the first is the
     * archive being recorded now; its stream_id is the live stream's id.
     */
    public function recentArchives(string $broadcasterId): Response
    {
        return $this->request($broadcasterId)->get('/videos', [
            'user_id' => $broadcasterId,
            'type' => 'archive',
            'first' => 5,
        ]);
    }

    /**
     * POST /videos/clips. Every parameter is a query parameter; there is no
     * body. The clip ends at vod_offset and starts at vod_offset - duration.
     */
    public function createClipFromVod(string $broadcasterId, string $vodId, int $vodOffset, int $duration, string $title): Response
    {
        $query = http_build_query([
            'broadcaster_id' => $broadcasterId,
            'editor_id' => $broadcasterId,
            'vod_id' => $vodId,
            'vod_offset' => $vodOffset,
            'duration' => $duration,
            'title' => $title,
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->request($broadcasterId)->post('/videos/clips?'.$query);
    }

    /** GET /clips?id=... Answers 200 with empty data until the clip exists. */
    public function getClip(string $broadcasterId, string $clipId): Response
    {
        return $this->request($broadcasterId)->get('/clips', ['id' => $clipId]);
    }

    /** GET /clips/downloads. Either URL may be null. */
    public function getClipDownload(string $broadcasterId, string $clipId): Response
    {
        return $this->request($broadcasterId)->get('/clips/downloads', [
            'broadcaster_id' => $broadcasterId,
            'editor_id' => $broadcasterId,
            'clip_id' => $clipId,
        ]);
    }

    /** Twitch's own error message from a Helix error body, if it gave one. */
    public static function message(Response $response): string
    {
        $message = $response->json('message');

        return is_string($message) ? mb_substr($message, 0, 300) : '';
    }

    /**
     * Seconds until the Helix rate-limit bucket refills, from Ratelimit-Reset
     * (a Unix time). At least 1, and 60 if Twitch did not say.
     *
     * @see https://dev.twitch.tv/docs/api/guide/#twitch-rate-limits
     */
    public static function retryAfter(Response $response): int
    {
        $reset = $response->header('Ratelimit-Reset');

        return is_numeric($reset) ? max(1, (int) $reset - now()->getTimestamp()) : 60;
    }

    private function request(string $broadcasterId): PendingRequest
    {
        return Twitch::asBroadcaster($broadcasterId)
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->retry(
                2,
                250,
                fn (Throwable $e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()),
                throw: false,
            );
    }
}
