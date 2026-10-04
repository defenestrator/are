<?php

namespace App\Jobs\Clips;

use App\Clips\ClipHelix;
use App\Clips\StreamMarkerStatus;
use App\Models\StreamMarker;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Download a ready clip's MP4s to the private clips disk (#11, slice 2).
 *
 * Get Clips Download URLs are temporary, so CreateClipForMarker queues this as
 * soon as they exist. A URL that has expired (or that the CDN refuses) is
 * fetched again from Twitch before downloading. Each variant is written to a
 * fixed path once and skipped after that, so retries and duplicate dispatches
 * do no harm. Paths stay on the server: mods play files only through the
 * moderate-gated clips.file route.
 *
 * Downloads use a plain HTTP client: the broadcaster's token is never sent to
 * the CDN, and only hosts in clips.download_hosts are fetched.
 */
class FetchClipFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    /** Below the default supervisor's 60 s; see config/horizon.php. */
    public int $timeout = 55;

    /** Refresh URLs this close to their expiry rather than race it. */
    private const EXPIRY_MARGIN_SECONDS = 60;

    public function __construct(public int $markerId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('clip-file:'.$this->markerId))->releaseAfter(30)->expireAfter(180)];
    }

    public function handle(ClipHelix $helix): void
    {
        $marker = StreamMarker::find($this->markerId);
        if ($marker === null || $marker->status !== StreamMarkerStatus::ClipReady || $marker->clip_id === null) {
            return;
        }

        $disk = Storage::disk(config('clips.disk'));
        if ($marker->fetched_at !== null && $this->filesPresent($marker, $disk)) {
            return;
        }

        if ($this->urlsStale($marker) && ! $this->refreshUrls($helix, $marker)) {
            return;
        }

        foreach (StreamMarker::VARIANTS as $variant) {
            $url = $marker->downloadUrl($variant);
            $path = $this->path($marker, $variant);

            if ($url === null || ($marker->filePath($variant) === $path && $disk->exists($path))) {
                continue;
            }

            if (! $this->download($marker, $disk, $url, $path)) {
                return;
            }
            $marker->update([$variant.'_file_path' => $path]);
        }

        $marker->refresh();
        $bytes = collect(StreamMarker::VARIANTS)
            ->map(fn ($variant) => $marker->filePath($variant))
            ->filter()
            ->sum(fn ($path) => $disk->size($path));

        $marker->update(['file_bytes' => $bytes, 'fetched_at' => now(), 'fetch_error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        StreamMarker::whereKey($this->markerId)->update([
            'fetch_error' => 'Gave up downloading the clip file'.($e !== null ? ': '.Str::limit($e->getMessage(), 300) : '.'),
        ]);
    }

    /**
     * Stream the file to a temporary file, check it, then write it to the
     * disk. A failure throws, so the queue retries with backoff. False when
     * the URL is not one we fetch at all, which no retry can fix.
     */
    private function download(StreamMarker $marker, Filesystem $disk, string $url, string $path): bool
    {
        if (! self::allowedUrl($url)) {
            $marker->update(['fetch_error' => 'Refused to fetch a clip file from '.(parse_url($url, PHP_URL_HOST) ?: 'an invalid URL').': not a Twitch download host.']);
            $this->fail(new RuntimeException('Clip download URL is not on an allowed host.'));

            return false;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'clip');
        if ($tmp === false) {
            throw new RuntimeException('Could not create a temporary file for the clip download.');
        }

        try {
            $response = Http::connectTimeout(5)->timeout(45)->sink($tmp)->get($url);

            if (in_array($response->status(), [401, 403, 404, 410], true)) {
                // Most likely an expired URL: fetch fresh ones on the retry.
                $marker->update(['download_urls_expire_at' => now()]);
                throw new RuntimeException('The clip CDN answered HTTP '.$response->status().'; fetching new download URLs and retrying.');
            }
            $response->throw();

            $this->check($response, $tmp);

            $stream = fopen($tmp, 'rb');
            if ($stream === false || ! $disk->writeStream($path, $stream)) {
                throw new RuntimeException('Could not write the clip file to the '.config('clips.disk').' disk.');
            }
            if (is_resource($stream)) {
                fclose($stream);
            }
        } finally {
            @unlink($tmp);
        }

        return true;
    }

    private function check(Response $response, string $tmp): void
    {
        $max = (int) config('clips.max_file_bytes');
        $size = (int) filesize($tmp);

        if ($size === 0) {
            throw new RuntimeException('The clip CDN returned an empty file.');
        }
        if ($size > $max) {
            throw new RuntimeException("The clip file is {$size} bytes, over the {$max}-byte limit.");
        }

        $type = strtolower((string) $response->header('Content-Type'));
        if ($type !== '' && ! str_starts_with($type, 'video/') && ! str_starts_with($type, 'application/octet-stream') && ! str_starts_with($type, 'binary/octet-stream')) {
            throw new RuntimeException("The clip CDN returned {$type}, not a video.");
        }
    }

    private function urlsStale(StreamMarker $marker): bool
    {
        return $marker->download_urls_expire_at === null
            || $marker->download_urls_expire_at->copy()->subSeconds(self::EXPIRY_MARGIN_SECONDS)->isPast();
    }

    /**
     * Ask Twitch for fresh download URLs. False when the job should stop for
     * now: released on a 429, or recorded on the marker when Twitch has none.
     */
    private function refreshUrls(ClipHelix $helix, StreamMarker $marker): bool
    {
        $response = $helix->getClipDownload($marker->broadcaster_id, (string) $marker->clip_id);

        if ($response->status() === 429) {
            $this->release(ClipHelix::retryAfter($response));

            return false;
        }
        $response->throw();

        $urls = ClipHelix::downloadUrls($response, (string) $marker->clip_id);
        if ($urls['landscape'] === null && $urls['portrait'] === null) {
            throw new RuntimeException('Twitch gave no download URL for the clip.');
        }

        $marker->update([
            'landscape_download_url' => $urls['landscape'],
            'portrait_download_url' => $urls['portrait'],
            'download_urls_expire_at' => CreateClipForMarker::expiry(array_values(array_filter($urls))),
        ]);

        return true;
    }

    private function filesPresent(StreamMarker $marker, Filesystem $disk): bool
    {
        $paths = array_filter(array_map(fn ($variant) => $marker->filePath($variant), StreamMarker::VARIANTS));

        return $paths !== [] && collect($paths)->every(fn ($path) => $disk->exists($path));
    }

    /** A fixed path per clip and variant, so a retry overwrites rather than duplicates. */
    private function path(StreamMarker $marker, string $variant): string
    {
        $clip = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $marker->clip_id) ?: 'clip';

        return 'clips/'.$marker->id.'/'.$clip.'-'.$variant.'.mp4';
    }

    /** HTTPS on a host in clips.download_hosts (exactly, or a subdomain of one). */
    public static function allowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        foreach ((array) config('clips.download_hosts') as $allowed) {
            $allowed = strtolower((string) $allowed);
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.'.$allowed))) {
                return true;
            }
        }

        return false;
    }
}
