<?php

namespace App\Models;

use Database\Factories\ShortLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * A UTM-tagged short link: GET /go/{code} counts the click, remembers the
 * UTM params in the session for lead attribution, and 302s to the
 * destination with the UTM params appended.
 *
 * The public interface is deliberately small, because overlays, clips and
 * analytics build on it:
 *
 *   $link = ShortLink::for('/about#work-with-us', 'twitch', 'stream', '2026-10-04-orkestera', 'overlay');
 *   $link->url();            // https://app/go/k3x9q2 (what you show on stream)
 *   $link->destinationUrl(); // https://app/about?utm_source=twitch&...#work-with-us
 *   ShortLink::attribution(); // UTM + short_link_id of the last link this session clicked
 *
 * Convention: utm_source is the channel (twitch, youtube), utm_medium is the
 * surface (stream, clip, vod), utm_campaign names the stream, and
 * utm_content says where on that surface the link appeared (overlay, chat).
 *
 * @property int $id
 * @property string $code
 * @property string $destination
 * @property string $tuple_hash
 * @property string $utm_source
 * @property string $utm_medium
 * @property string $utm_campaign
 * @property string|null $utm_content
 * @property int $clicks
 */
class ShortLink extends Model
{
    /** @use HasFactory<ShortLinkFactory> */
    use HasFactory;

    /** Session key holding the last-clicked link's attribution. */
    public const SESSION_KEY = 'lead_attribution';

    public const UTM_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'];

    /** Session key prefix holding when this session's click on a link was last counted. */
    public const SEEN_SESSION_KEY = 'short_link_seen';

    /** A repeat click from the same session within this window is not a new click. */
    public const DEDUPE_MINUTES = 30;

    /** Counted clicks per link and IP address per DEDUPE_MINUTES, for clients without a session. */
    public const MAX_COUNTED_PER_IP = 5;

    /**
     * Link unfurlers, crawlers and scripted clients. They are redirected but
     * never counted. iMessage previews identify as facebookexternalhit and
     * Twitterbot, so they are covered here. The tokens name the preview
     * fetchers rather than the apps, so in-app browsers (LinkedInApp,
     * "Twitter for iPhone", FBAN) still count as people.
     */
    public const BOT_USER_AGENTS = '/bot\b|bot\/|crawl|spider|slurp|facebookexternalhit|facebot|discordbot|slackbot|slack-imgproxy|twitterbot|linkedinbot|whatsapp\/|telegrambot|skypeuripreview|bingpreview|google-pagerenderer|embedly|iframely|pinterest|vkshare|mastodon|bluesky|cardyb|bitlybot|headless|lighthouse|python-requests|python-urllib|aiohttp|httpx|curl\/|wget|go-http-client|okhttp|axios|node-fetch|undici|java\/|libwww|scrapy|httpclient|guzzlehttp/i';

    protected $fillable = [
        'code',
        'destination',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
    ];

    protected $attributes = [
        'clicks' => 0,
    ];

    protected function casts(): array
    {
        return [
            'clicks' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // tuple_hash backs the unique index that makes one destination + UTM
        // tuple map to exactly one code. destination is too long for a
        // composite unique index on MySQL, so the index is on its hash.
        static::saving(function (ShortLink $link) {
            $link->tuple_hash = static::tupleHash(
                $link->destination,
                $link->utm_source,
                $link->utm_medium,
                $link->utm_campaign,
                $link->utm_content,
            );
        });
    }

    /**
     * Find or create the short link for this destination and UTM tuple, so
     * callers can ask for a link on every render without minting duplicates.
     * Concurrent first calls are safe: the loser of the insert race hits the
     * unique index on tuple_hash and firstOrCreate returns the winner's row.
     */
    public static function for(
        string $destination,
        string $source,
        string $medium,
        string $campaign,
        ?string $content = null,
    ): self {
        $content = $content === '' ? null : $content;

        return static::firstOrCreate(
            ['tuple_hash' => static::tupleHash($destination, $source, $medium, $campaign, $content)],
            fn () => [
                'code' => static::generateCode(),
                'destination' => $destination,
                'utm_source' => $source,
                'utm_medium' => $medium,
                'utm_campaign' => $campaign,
                'utm_content' => $content,
            ],
        );
    }

    /** SHA-256 of the destination and UTM tuple; unique across short_links. */
    public static function tupleHash(
        string $destination,
        string $source,
        string $medium,
        string $campaign,
        ?string $content = null,
    ): string {
        return hash('sha256', (string) json_encode([$destination, $source, $medium, $campaign, $content === '' ? null : $content]));
    }

    /**
     * The attribution stored by the last short link clicked in this session:
     * the utm_* keys plus short_link_id, or an empty array.
     *
     * @return array<string, int|string|null>
     */
    public static function attribution(): array
    {
        $stored = session(self::SESSION_KEY);

        return is_array($stored) ? $stored : [];
    }

    public static function generateCode(int $length = 6): string
    {
        do {
            $code = Str::lower(Str::random($length));
        } while (static::where('code', $code)->exists());

        return $code;
    }

    /** The short URL to show viewers. */
    public function url(): string
    {
        return route('short-links.go', $this->code);
    }

    /** The destination with this link's UTM params appended, keeping any fragment. */
    public function destinationUrl(): string
    {
        [$base, $fragment] = array_pad(explode('#', $this->destination, 2), 2, null);

        $url = url($base ?? '/');
        $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($this->utm());

        return $fragment === null ? $url : $url.'#'.$fragment;
    }

    /**
     * Dated clicks, one row each, for counting clicks in a date range.
     *
     * @return HasMany<ShortLinkClick, $this>
     */
    public function clickEvents(): HasMany
    {
        return $this->hasMany(ShortLinkClick::class);
    }

    /**
     * Record a visit to /go/{code}. This is the one place that decides whether a
     * hit counts, and it keeps the `clicks` counter and the dated
     * `short_link_clicks` rows in step: both are written or neither is.
     *
     * - HEAD requests, prefetches, and link-preview or bot user agents are not
     *   clicks: they record nothing and leave the session alone.
     * - A person's click always refreshes their attribution (last click wins),
     *   but the same session clicking the same link again within
     *   DEDUPE_MINUTES is the same click, not a new one.
     * - At most MAX_COUNTED_PER_IP clicks per link and IP address count per
     *   DEDUPE_MINUTES, which catches clients that drop the session cookie.
     *
     * Returns whether the click was counted. The caller redirects either way.
     */
    public function recordClick(?Request $request = null): bool
    {
        $request ??= request();

        if (! static::isHumanClick($request)) {
            return false;
        }

        session()->put(self::SESSION_KEY, [...$this->utm(), 'short_link_id' => $this->id]);

        $seenKey = self::SEEN_SESSION_KEY.'.'.$this->id;
        $lastCounted = session($seenKey);

        if (is_int($lastCounted) && $lastCounted > now()->subMinutes(self::DEDUPE_MINUTES)->getTimestamp()) {
            return false;
        }

        $ipKey = 'short-link-click:'.$this->id.':'.sha1((string) $request->ip());

        if (RateLimiter::tooManyAttempts($ipKey, self::MAX_COUNTED_PER_IP)) {
            return false;
        }

        RateLimiter::hit($ipKey, self::DEDUPE_MINUTES * 60);
        session()->put($seenKey, now()->getTimestamp());

        DB::transaction(function () {
            $this->increment('clicks');
            $this->clickEvents()->create(['clicked_at' => now()]);
        });

        return true;
    }

    /** Whether this request is a person following the link, not a preview, prefetch or bot. */
    public static function isHumanClick(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        foreach (['Purpose', 'Sec-Purpose', 'X-Purpose', 'X-Moz'] as $header) {
            if (str_contains(strtolower((string) $request->header($header)), 'prefetch')
                || str_contains(strtolower((string) $request->header($header)), 'preview')) {
                return false;
            }
        }

        $agent = trim((string) $request->userAgent());

        return $agent !== '' && preg_match(self::BOT_USER_AGENTS, $agent) !== 1;
    }

    /**
     * The non-empty UTM params, keyed by their query-string names.
     *
     * @return array<string, string>
     */
    public function utm(): array
    {
        return array_filter(
            $this->only(self::UTM_FIELDS),
            fn ($value) => $value !== null && $value !== '',
        );
    }
}
