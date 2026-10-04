<?php

namespace App\Jobs\Clips;

use App\Clips\ClipHelix;
use App\Clips\StreamMarkerStatus;
use App\Models\BroadcasterToken;
use App\Models\StreamMarker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cut the clip for a !clip marker while the stream is live (#11, slice 1).
 *
 * 1. Find the VOD being recorded (Get Videos, type=archive, matched on the
 *    stream session's id) and call Create Clip From VOD, ending 15 s after
 *    the marker, 60 s long. Twitch's 404 says the broadcaster must be live, so
 *    this runs shortly after the marker, not after the stream (spike #25).
 * 2. Poll Get Clips until the clip exists, for up to 60 s after creating it.
 * 3. Get Clips Download, and store both MP4 URLs and when they expire.
 *
 * Each step is saved before the next, and the job releases itself back onto
 * the queue between polls, so a retry carries on from where the last attempt
 * stopped: it never creates a second clip once one clip id is stored. If an
 * attempt was sent but no id came back, the retry first looks in Get Clips
 * for the clip that attempt made, and reuses it. When the clip is ready,
 * FetchClipFile downloads the files.
 */
class CreateClipForMarker implements ShouldQueue
{
    use Queueable;

    /** The clip ends this many seconds after the marker. */
    public const LEAD_OUT_SECONDS = 15;

    /** Helix allows 5 to 60 s. */
    public const DURATION_SECONDS = 60;

    /** Get Clips is polled this often... */
    public const POLL_SECONDS = 5;

    /** ...for at most this long after Create Clip From VOD; then the clip has failed. */
    public const POLL_TIMEOUT_SECONDS = 60;

    /** Used when the mod's note fails AutoMod as a clip title. */
    public const FALLBACK_TITLE = 'EDOS live clip';

    /** A retry reuses a clip made up to this long before the last attempt. */
    public const REUSE_WINDOW_SECONDS = 60;

    /** Seconds of slack when matching an earlier clip's start in the VOD. */
    public const REUSE_OFFSET_TOLERANCE = 2;

    /** Helix's limit is not documented; the Twitch clip editor allows 100. */
    public const TITLE_MAX = 100;

    /** @var list<int> Backoff for connection failures and Twitch 5xx. */
    public array $backoff = [5, 15, 30];

    public function __construct(public int $markerId) {}

    /**
     * Releases count as attempts, so bound the job by time instead. Long
     * enough for the polls and a few 5xx retries; well within the stream.
     */
    public function retryUntil(): Carbon
    {
        return now()->addMinutes(15);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('stream-marker:'.$this->markerId))->releaseAfter(self::POLL_SECONDS)->expireAfter(120)];
    }

    public function handle(ClipHelix $helix): void
    {
        $marker = StreamMarker::with('streamSession')->find($this->markerId);
        if ($marker === null || $marker->status->isFinal() || $marker->twitch_marker_id === null) {
            return;
        }

        $token = BroadcasterToken::where('broadcaster_id', $marker->broadcaster_id)->first();
        if ($token === null || ! in_array(ClipHelix::CLIPS_SCOPE, (array) $token->scopes, true)) {
            $marker->fail(StreamMarkerStatus::ClipFailed, 'The broadcaster has not granted '.ClipHelix::CLIPS_SCOPE.'. They need to reconnect at /twitch/broadcaster/connect; the marker itself is saved.');

            return;
        }

        if ($marker->clip_id === null) {
            $this->createClip($helix, $marker);
        } else {
            $this->collectClip($helix, $marker);
        }
    }

    /**
     * The job gave up (out of time, or an exception it could not retry). The
     * marker is kept and shows why.
     */
    public function failed(?Throwable $e): void
    {
        $marker = StreamMarker::find($this->markerId);

        if ($marker !== null && ! $marker->status->isFinal()) {
            $marker->fail(StreamMarkerStatus::ClipFailed, 'Gave up after repeated errors talking to Twitch'.($e !== null ? ' ('.class_basename($e).')' : '').'.');
        }
    }

    private function createClip(ClipHelix $helix, StreamMarker $marker): void
    {
        if ($marker->vod_id === null) {
            $vodId = $this->findVod($helix, $marker);
            if ($vodId === null) {
                return;
            }
            $marker->update(['vod_id' => $vodId]);
        }

        // vod_offset is where the clip ends and must be at least the duration.
        $vodOffset = (int) $marker->position_seconds + self::LEAD_OUT_SECONDS;
        $duration = min(self::DURATION_SECONDS, $vodOffset);
        $title = $this->title($marker);

        // A retry after an attempt that may have reached Twitch (a timeout, a
        // 5xx, a worker that died) looks for that attempt's clip first, so it
        // does not make a second one.
        if ($marker->clip_attempted_at !== null) {
            $lookup = $helix->clipsSince($marker->broadcaster_id, $marker->clip_attempted_at->copy()->subSeconds(self::REUSE_WINDOW_SECONDS));

            if ($lookup->status() === 429) {
                $this->release(ClipHelix::retryAfter($lookup));

                return;
            }
            if ($lookup->serverError()) {
                $lookup->throw();
            }

            $earlier = $lookup->successful() ? $this->earlierClip($marker, (array) $lookup->json('data'), $vodOffset - $duration, [$title, self::FALLBACK_TITLE]) : null;
            if ($earlier !== null) {
                $marker->update([
                    'clip_id' => (string) $earlier['id'],
                    'clip_edit_url' => isset($earlier['url']) ? (string) $earlier['url'] : null,
                    'clip_requested_at' => now(),
                    'status' => StreamMarkerStatus::ClipProcessing,
                    'error' => null,
                ]);
                $this->release(self::POLL_SECONDS);

                return;
            }
        }

        // Recorded before the request goes out: if this attempt dies after
        // Twitch made the clip, the next one knows to look for it.
        $marker->update(['clip_attempted_at' => now()]);

        $response = $helix->createClipFromVod($marker->broadcaster_id, (string) $marker->vod_id, $vodOffset, $duration, $title);

        if ($response->status() === 400 && Str::contains(ClipHelix::message($response), 'AutoMod', true) && $title !== self::FALLBACK_TITLE) {
            $response = $helix->createClipFromVod($marker->broadcaster_id, (string) $marker->vod_id, $vodOffset, $duration, self::FALLBACK_TITLE);
        }

        if (! $this->usable($response, $marker, fn () => $this->clipFailure($response))) {
            return;
        }

        $clip = $response->json('data.0');
        if (! is_array($clip) || empty($clip['id'])) {
            $marker->fail(StreamMarkerStatus::ClipFailed, 'Twitch accepted the clip request but returned no clip id.');

            return;
        }

        // Saved before anything else can fail, so no retry makes a second clip.
        $marker->update([
            'clip_id' => (string) $clip['id'],
            'clip_edit_url' => isset($clip['edit_url']) ? (string) $clip['edit_url'] : null,
            'clip_requested_at' => now(),
            'status' => StreamMarkerStatus::ClipProcessing,
            'error' => null,
        ]);

        // Clipping is asynchronous: check back shortly.
        $this->release(self::POLL_SECONDS);
    }

    private function collectClip(ClipHelix $helix, StreamMarker $marker): void
    {
        $response = $helix->getClip($marker->broadcaster_id, (string) $marker->clip_id);
        if (! $this->usable($response, $marker, fn () => 'Get Clips failed (HTTP '.$response->status().').')) {
            return;
        }

        if (empty($response->json('data'))) {
            $this->pollAgainOrFail($marker, 'Twitch did not finish the clip within '.self::POLL_TIMEOUT_SECONDS.' s, so it is treated as failed.');

            return;
        }

        $downloads = $helix->getClipDownload($marker->broadcaster_id, (string) $marker->clip_id);
        if (! $this->usable($downloads, $marker, fn () => 'Get Clips Download failed (HTTP '.$downloads->status().').')) {
            return;
        }

        ['landscape' => $landscape, 'portrait' => $portrait] = ClipHelix::downloadUrls($downloads, (string) $marker->clip_id);

        if ($landscape === null && $portrait === null) {
            $this->pollAgainOrFail($marker, 'The clip exists but Twitch gave no download URL for it.');

            return;
        }

        $marker->update([
            'landscape_download_url' => $landscape,
            'portrait_download_url' => $portrait,
            'download_urls_expire_at' => self::expiry(array_filter([$landscape, $portrait])),
            'clip_duration_seconds' => is_numeric($response->json('data.0.duration')) ? (float) $response->json('data.0.duration') : null,
            'status' => StreamMarkerStatus::ClipReady,
            'error' => null,
        ]);

        // Fetch the files now: the download URLs are temporary.
        FetchClipFile::dispatch($marker->id);
    }

    /**
     * A clip in Get Clips that an earlier attempt for this marker made: the
     * broadcaster created it (ARE clips with their token) from this VOD at
     * this offset. Get Clips leaves vod_offset null for minutes after a live
     * clip, so a clip without one matches on the title instead. A clip that
     * another marker already owns never matches.
     *
     * @param  array<int, mixed>  $clips
     * @param  list<string>  $titles
     * @return array<string, mixed>|null
     */
    private function earlierClip(StreamMarker $marker, array $clips, int $start, array $titles): ?array
    {
        $taken = StreamMarker::whereNotNull('clip_id')->where('broadcaster_id', $marker->broadcaster_id)->pluck('clip_id')->all();

        foreach ($clips as $clip) {
            if (! is_array($clip) || empty($clip['id']) || in_array((string) $clip['id'], $taken, true)) {
                continue;
            }
            if ((string) ($clip['creator_id'] ?? '') !== $marker->broadcaster_id) {
                continue;
            }
            $videoId = (string) ($clip['video_id'] ?? '');
            if ($videoId !== '' && $videoId !== $marker->vod_id) {
                continue;
            }

            $offset = $clip['vod_offset'] ?? null;
            $matches = is_numeric($offset)
                ? abs((int) $offset - $start) <= self::REUSE_OFFSET_TOLERANCE
                : in_array((string) ($clip['title'] ?? ''), $titles, true);

            if ($matches) {
                return $clip;
            }
        }

        return null;
    }

    /**
     * The id of the VOD being recorded for the marker's stream, or null after
     * marking the clip failed (or releasing the job, on a rate limit).
     */
    private function findVod(ClipHelix $helix, StreamMarker $marker): ?string
    {
        $response = $helix->recentArchives($marker->broadcaster_id);
        if (! $this->usable($response, $marker, fn () => 'Get Videos failed (HTTP '.$response->status().').')) {
            return null;
        }

        $videos = collect((array) $response->json('data'));
        $streamId = $marker->streamSession?->twitch_stream_id;

        // Without a stream session (stream.online was missed), the newest
        // archive is the one being recorded: Twitch only made the marker
        // because the channel is live.
        $video = $streamId !== null
            ? $videos->first(fn ($v) => is_array($v) && (string) ($v['stream_id'] ?? '') === $streamId)
            : $videos->first();

        if (! is_array($video) || empty($video['id'])) {
            $marker->fail(StreamMarkerStatus::ClipFailed, 'Twitch has no VOD of this stream to clip from. Turn on "Store past broadcasts" (Creator Dashboard, Settings, Stream, VOD Settings).');

            return null;
        }

        return (string) $video['id'];
    }

    /**
     * Whether a Helix response can be read. If not, the job is released on a
     * 429, thrown for a retry on a 5xx, or the marker fails with $reason.
     *
     * @param  callable(): string  $reason
     */
    private function usable(Response $response, StreamMarker $marker, callable $reason): bool
    {
        if ($response->successful()) {
            return true;
        }

        if ($response->status() === 429) {
            $this->release(ClipHelix::retryAfter($response));

            return false;
        }

        if ($response->serverError()) {
            $response->throw();
        }

        $message = $reason();
        $twitchSays = ClipHelix::message($response);
        $marker->fail(StreamMarkerStatus::ClipFailed, $twitchSays !== '' ? $message.' Twitch said: '.$twitchSays : $message);

        return false;
    }

    private function pollAgainOrFail(StreamMarker $marker, string $reason): void
    {
        $deadline = ($marker->clip_requested_at ?? now())->copy()->addSeconds(self::POLL_TIMEOUT_SECONDS);

        if (now()->lt($deadline)) {
            $this->release(self::POLL_SECONDS);

            return;
        }

        $marker->fail(StreamMarkerStatus::ClipFailed, $reason);
    }

    private function clipFailure(Response $response): string
    {
        $twitchSays = ClipHelix::message($response);

        return match ($response->status()) {
            404 => Str::contains($twitchSays, 'live', true)
                ? 'The stream was no longer live, and Twitch only clips the VOD of a live stream.'
                : 'Twitch could not find the VOD to clip. Check that "Store past broadcasts" is on.',
            400 => Str::contains($twitchSays, 'clippable', true)
                ? 'Twitch does not allow clips in the stream\'s current category.'
                : 'Twitch rejected the clip request.',
            401 => 'Twitch refused the broadcaster\'s token for clips. The broadcaster needs to reconnect at /twitch/broadcaster/connect to grant '.ClipHelix::CLIPS_SCOPE.'.',
            403 => 'Twitch would not let the broadcaster\'s token clip: clips may be off, or limited to followers or subscribers, on this channel.',
            default => 'Create Clip From VOD failed (HTTP '.$response->status().').',
        };
    }

    private function title(StreamMarker $marker): string
    {
        $title = trim((string) $marker->description);

        return $title === '' ? self::FALLBACK_TITLE : mb_substr($title, 0, self::TITLE_MAX);
    }

    /**
     * When the download URLs stop working. Twitch documents them only as
     * "temporary". A signed URL usually says when it expires (CloudFront
     * Expires, or S3's X-Amz-Date plus X-Amz-Expires), and we take the
     * earliest. Otherwise assume clips.download_url_ttl_seconds from now.
     *
     * @param  array<int, string>  $urls
     */
    public static function expiry(array $urls): Carbon
    {
        $expiries = [];

        foreach ($urls as $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $query = array_change_key_case($query, CASE_LOWER);

            if (is_numeric($query['expires'] ?? null)) {
                $expiries[] = Carbon::createFromTimestamp((int) $query['expires']);
            } elseif (is_string($query['x-amz-date'] ?? null) && is_numeric($query['x-amz-expires'] ?? null)) {
                $signed = Carbon::createFromFormat('Ymd\THis\Z', $query['x-amz-date'], 'UTC');
                if ($signed !== null) {
                    $expiries[] = $signed->addSeconds((int) $query['x-amz-expires']);
                }
            }
        }

        if ($expiries === []) {
            return now()->addSeconds((int) config('clips.download_url_ttl_seconds'));
        }

        return collect($expiries)->sortBy(fn (Carbon $c) => $c->getTimestamp())->first();
    }
}
